<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use Freyr\MessageBroker\ErrorHandler;
use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Worker\WorkerLimitGuard;
use Freyr\MessageBroker\Worker\WorkerLimits;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Outbox relay. Any number of identical worker processes drain one lane
 * concurrently: each claims a batch of unpublished, due rows via FOR UPDATE
 * SKIP LOCKED inside a store-owned transaction, publishes while holding the
 * claim, and marks the rows published on success (published rows stay for
 * partition retention; a partition with unpublished rows is never dropped by
 * default). Workers never block each other; a crashed worker's rows are
 * instantly reclaimable (its locks die with its connection) and consumer
 * dedup absorbs the republish.
 *
 * The relay holds the loop only. The transport work is behind the
 * {@see OutboxPublisher} port.
 *
 * NO ordering promise: rows overtake freely — across workers and past
 * backing-off rows. Consumers must be order-insensitive.
 *
 * Publish calls of one claim:
 *  - A row that failed before (attempts > 0) is published alone, one call
 *    each, before the fresh rows, so a message the transport refuses fails
 *    alone.
 *  - The fresh rows (attempts === 0) are published in chunks of
 *    {@see OutboxPublisher::maxBatchSize()}; each chunk is one call.
 *
 * Failure handling:
 *  - The publisher refuses single rows: only the refused rows back off; the
 *    other rows of the call count as published.
 *  - A publisher call throws (channel, connection or confirm-timeout failure):
 *    crash-only. The rows of that call back off (committed), the results of
 *    earlier calls stay, then the error propagates and the worker stops; the
 *    supervisor restarts it with a fresh connection. Rows that the relay did
 *    not attempt (later chunks) stay due, untouched.
 *
 * Each worker must OWN its publisher resources (for example the AMQP
 * channel) and its database connection exclusively.
 */
final class Relay
{
    private bool $shouldStop = false;

    private readonly Backoff $backoff;

    public function __construct(
        private readonly OutboxStore $outbox,
        private readonly OutboxPublisher $publisher,
        private readonly string $lane = 'default',
        private readonly int $batchSize = 500,
        ?Backoff $backoff = null,
        private readonly ?ErrorHandler $errorHandler = null,
        private readonly int $idleSleepMs = 200,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?BrokerEvents $events = null,
    ) {
        $this->backoff = $backoff ?? Backoff::exponential(initialDelayMs: 1_000, maxDelayMs: 300_000);
    }

    /**
     * Long-running entrypoint; stops on SIGTERM/SIGINT when pcntl is
     * available, and when a limit of {@see WorkerLimits} is reached. A limit
     * is checked at the end of each pass, so the run can publish up to one
     * batch above a message limit. A limit stop calls {@see stop()}, as a
     * signal does. A broker failure propagates out of it (crash-only).
     */
    public function run(?WorkerLimits $limits = null): void
    {
        $guard = new WorkerLimitGuard($limits ?? new WorkerLimits());
        $guard->start();
        $this->registerSignalHandlers();

        while (!$this->shouldStop) {
            $published = $this->drainOnce();
            if ($published === 0) {
                usleep(IdleSleep::micros($this->idleSleepMs, intdiv($this->idleSleepMs, 4)));
            }

            $guard->recordMessages($published);
            $reason = $guard->stopReason();
            if ($reason !== null) {
                $this->logger->info('Worker limit reached; the worker stops', [
                    'lane' => $this->lane,
                    'reason' => $reason->value,
                    'messages' => $guard->messages(),
                    'failures' => $guard->failures(),
                    'elapsedSeconds' => $guard->elapsedMs() / 1_000.0,
                    'memoryBytes' => $guard->usedMemory(),
                ]);
                $this->stop();
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
     * @throws Throwable a publisher failure (channel, connection or confirm timeout), after the failed rows are backed off
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
                foreach (array_chunk($fresh, $this->publisher->maxBatchSize()) as $chunk) {
                    /** @var non-empty-list<OutboxRecord> $chunk */
                    $current = $chunk;
                    $this->collect($current, $this->publisher->publishBatch($current), $published, $retryAtMs);
                }
            }
        } catch (Throwable $error) {
            // The rows of the failed call back off; earlier calls keep their result; rows not attempted stay due.
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
     * @param list<string> $refusedIds
     * @param list<string> $published
     * @param array<string, int> $retryAtMs
     */
    private function collect(array $batch, array $refusedIds, array &$published, array &$retryAtMs): void
    {
        $refused = [];
        foreach ($batch as $record) {
            if (in_array($record->id, $refusedIds, true)) {
                $retryAtMs[$record->id] = $this->retryAt($record);
                $refused[] = $record;
            } else {
                $published[] = $record->id;
            }
        }
        if ($refused !== []) {
            $this->report('Broker nacked messages; they back off', null, $refused);
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
        // A retried row goes alone (one call, one row), so a failure of that call names the row
        // that fails again: its ID, its name and the number of the attempt that just failed.
        if (count($records) === 1 && $records[0]->attempts > 0) {
            $context += [
                'message_id' => $records[0]->id,
                'message_name' => $records[0]->messageName,
                'attempts' => $records[0]->attempts + 1,
            ];
        }
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
