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
 * Competing AMQP relay — the opt-in parallel drain (spec D-C1). N identical
 * worker processes drain ONE lane concurrently: each claims a batch of
 * eligible rows via FOR UPDATE SKIP LOCKED inside a store-owned transaction,
 * publishes while holding the claim, deletes on success. Workers never block
 * each other; a crashed worker's rows are instantly reclaimable (its locks
 * die with its connection) and consumer dedup absorbs the republish.
 *
 * NO ordering promise (D-C5): rows overtake freely — across workers and past
 * backing-off rows. Consumers of this lane must be order-insensitive. For
 * ordered publishing use AmqpRelay (one owner per lane); for strict per-key
 * FIFO use Kafka.
 *
 * Failure is batch-granular (D-C4): AMQP publish failures are channel-level,
 * so on any failure every claimed row backs off by its OWN attempts count and
 * nothing is deleted. There is still no relay-side DLQ in any mode.
 *
 * Each worker must OWN its AMQPChannel and its PDO connection exclusively.
 */
final class CompetingAmqpRelay
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
        // No shutdown hook: there is no lane lock to release — claims are
        // transaction-scoped and never survive drainOnce().
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    /** One claim-publish-commit pass. @return int rows published and deleted */
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
     * Batch-granular backoff (D-C4): every claimed row retries at its own
     * attempts-derived delay; nothing is deleted. Rows already on the wire
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
