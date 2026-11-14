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
 * Failure is batch-granular: AMQP publish failures are channel-level, so on
 * any failure every claimed row backs off by its OWN attempts count and
 * nothing is marked published. There is no relay-side DLQ.
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
        private readonly int $batchSize = 100,
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

    /** Long-running entrypoint; stops on SIGTERM/SIGINT when pcntl is available. */
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

    /** One claim-publish-commit pass. @return int rows published and marked published */
    public function drainOnce(): int
    {
        $published = $this->outbox->drainClaimed(
            $this->lane,
            $this->batchSize,
            function (array $claimed): ClaimOutcome {
                try {
                    $this->publisher->publishBatch($claimed);
                } catch (Throwable $error) {
                    return $this->backOff($claimed, $error);
                }

                return ClaimOutcome::published(
                    array_map(static fn (OutboxRecord $record): string => $record->id, $claimed),
                );
            },
        );

        if ($published > 0) {
            $this->events?->record(BrokerEvents::RELAYED, [
                'lane' => $this->lane,
                'count' => $published,
            ]);
        }

        return $published;
    }

    /**
     * Batch-granular backoff: every claimed row retries at its own
     * attempts-derived delay; nothing is marked published. Rows already on the wire
     * republish later — consumer dedup absorbs them.
     *
     * @param non-empty-list<OutboxRecord> $claimed
     */
    private function backOff(array $claimed, Throwable $error): ClaimOutcome
    {
        $retryAtMs = [];
        foreach ($claimed as $record) {
            $retryAtMs[$record->id] = EpochMillis::now() + $this->backoff->delayForAttempt($record->attempts + 1);
        }

        $context = [
            'lane' => $this->lane,
            'claimed' => count($claimed),
            'attempt' => $claimed[0]->attempts + 1,
            'retry_in_ms' => $this->backoff->delayForAttempt($claimed[0]->attempts + 1),
        ];
        $this->logger->warning('Relay publish failed; claimed batch backing off', [
            'exception' => $error,
        ] + $context);
        $this->errorHandler?->handle($error, $context);

        return ClaimOutcome::retryAll($retryAtMs);
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
