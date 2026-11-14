<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp;

use Freyr\MessageBroker\ErrorHandler;
use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Outbox\ClaimOutcome;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Transport\IdleSleep;
use PhpAmqpLib\Channel\AMQPChannel;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * AMQP relay. Any number of identical worker processes drain one lane
 * concurrently: each claims a batch of unpublished, due rows via FOR UPDATE
 * SKIP LOCKED inside a store-owned transaction, publishes while holding the
 * claim, and marks the rows published on success (published rows stay for
 * partition retention). Workers never block each other; a crashed worker's
 * rows are instantly reclaimable (its locks die with its connection) and
 * consumer dedup absorbs the republish.
 *
 * NO ordering promise: rows overtake freely — across workers and past
 * backing-off rows. Consumers must be order-insensitive.
 *
 * Failure handling:
 *  - A broker nack backs off only the nacked rows; the channel stays usable.
 *  - A channel, connection or confirm-timeout failure is crash-only: the
 *    failed rows back off (committed), then the error propagates and the
 *    worker stops; the supervisor restarts it with a fresh connection.
 *  - A row that failed before (attempts > 0) is published alone, with its own
 *    confirm, so a message the broker refuses fails alone. Rows of the claim
 *    that were not attempted stay due, untouched.
 *
 * Each worker must OWN its AMQPChannel and its PDO connection exclusively.
 */
final class AmqpRelay
{
    private bool $shouldStop = false;

    private readonly Backoff $backoff;

    private readonly AmqpMessagePublisher $publisher;

    public function __construct(
        private readonly OutboxStore $outbox,
        AMQPChannel $amqp,
        AmqpPublishConfig $publish,
        string $contentType,
        private readonly string $lane = 'default',
        private readonly int $batchSize = 500,
        ?Backoff $backoff = null,
        private readonly ?ErrorHandler $errorHandler = null,
        private readonly int $idleSleepMs = 200,
        int $confirmTimeoutSec = 5,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?BrokerEvents $events = null,
    ) {
        $this->backoff = $backoff ?? Backoff::exponential(initialDelayMs: 1_000, maxDelayMs: 300_000);
        $this->publisher = new AmqpMessagePublisher($amqp, $publish, $contentType, $confirmTimeoutSec);
    }

    /**
     * Long-running entrypoint; stops on SIGTERM/SIGINT when pcntl is
     * available. A broker failure propagates out of it (crash-only).
     */
    public function run(): void
    {
        $this->registerSignalHandlers();

        while (!$this->shouldStop) {
            if ($this->drainOnce() === 0) {
                usleep(IdleSleep::micros($this->idleSleepMs, intdiv($this->idleSleepMs, 4)));
            }
        }
        // No shutdown hook: claims are transaction-scoped and never survive
        // drainOnce().
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    /**
     * One claim-publish-commit pass.
     *
     * @return int rows published and marked published
     *
     * @throws Throwable a channel, connection or confirm-timeout failure, after the failed rows are backed off
     */
    public function drainOnce(): int
    {
        $failure = null;
        $published = $this->outbox->drainClaimed(
            $this->lane,
            $this->batchSize,
            function (array $claimed) use (&$failure): ClaimOutcome {
                /** @var non-empty-list<OutboxRecord> $claimed */
                [$outcome, $failure] = $this->publish($claimed);

                return $outcome;
            },
        );

        if ($published > 0) {
            $this->events?->record(BrokerEvents::RELAYED, [
                'lane' => $this->lane,
                'count' => $published,
            ]);
        }

        if ($failure instanceof Throwable) {
            throw $failure; // crash-only: the supervisor restarts the worker with a fresh channel
        }

        return $published;
    }

    /**
     * @param non-empty-list<OutboxRecord> $claimed
     *
     * @return array{ClaimOutcome, ?Throwable}
     */
    private function publish(array $claimed): array
    {
        $retried = array_values(array_filter($claimed, static fn (OutboxRecord $r): bool => $r->attempts > 0));
        $fresh = array_values(array_filter($claimed, static fn (OutboxRecord $r): bool => $r->attempts === 0));

        $published = [];
        $retryAtMs = [];
        $current = [];
        try {
            foreach ($retried as $record) {
                $current = [$record];
                $this->collect($current, $this->publisher->publishBatch($current), $published, $retryAtMs);
            }
            if ($fresh !== []) {
                $current = $fresh;
                $this->collect($current, $this->publisher->publishBatch($current), $published, $retryAtMs);
            }
        } catch (Throwable $error) {
            // The rows of the failed publish back off; rows not attempted stay due.
            foreach ($current as $record) {
                $retryAtMs[$record->id] = $this->retryAt($record);
            }
            $this->report('Relay publish failed; the failed rows back off and the worker stops', $error, $current);

            return [new ClaimOutcome($published, $retryAtMs), $error];
        }

        return [new ClaimOutcome($published, $retryAtMs), null];
    }

    /**
     * @param non-empty-list<OutboxRecord> $batch
     * @param list<string> $nackedIds
     * @param list<string> $published
     * @param array<string, int> $retryAtMs
     */
    private function collect(array $batch, array $nackedIds, array &$published, array &$retryAtMs): void
    {
        $nacked = [];
        foreach ($batch as $record) {
            if (in_array($record->id, $nackedIds, true)) {
                $retryAtMs[$record->id] = $this->retryAt($record);
                $nacked[] = $record;
            } else {
                $published[] = $record->id;
            }
        }
        if ($nacked !== []) {
            $this->report('Broker nacked messages; they back off', null, $nacked);
        }
    }

    private function retryAt(OutboxRecord $record): int
    {
        return EpochMillis::now() + $this->backoff->delayForAttempt($record->attempts + 1);
    }

    /** @param list<OutboxRecord> $records */
    private function report(string $message, ?Throwable $error, array $records): void
    {
        $context = [
            'lane' => $this->lane,
            'rows' => count($records),
            'ids' => array_map(static fn (OutboxRecord $r): string => $r->id, array_slice($records, 0, 20)),
        ];
        $this->logger->warning($message, ($error === null ? [] : [
            'exception' => $error,
        ]) + $context);
        if ($error !== null) {
            $this->errorHandler?->handle($error, $context);
        }
    }

    private function registerSignalHandlers(): void
    {
        if (!extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->stop());
        pcntl_signal(SIGINT, fn () => $this->stop());
    }
}
