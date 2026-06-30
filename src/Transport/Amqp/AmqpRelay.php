<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp;

use Freyr\MessageBroker\ErrorHandler;
use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Transport\IdleSleep;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * No Serializer dependency — the relay pumps bytes and explodes the metadata column into individual x-message-* headers; it never parses the body.
 *
 * Dedicated AMQP relay: drains ONE outbox lane to ONE exchange, always
 * preserving total order within the lane (D17).
 *
 * Batched drain: the whole eligible prefix is published in id order, then
 * ONE confirm wait covers the batch, then rows are deleted in chunks
 * (~30x over per-message confirm+delete, see the throughput research note).
 * On any failure nothing is deleted — the head is backed off and the whole
 * batch republishes in order on a later pass; consumer deduplication
 * absorbs the duplicates (at-least-once).
 *
 * No DLQ on this path: a committed outbox row is publishable by definition
 * (validated at produce time), so failures are transient — retry with
 * backoff indefinitely; a long-blocked lane is an operational alert
 * surfaced through the error handler, never data loss.
 *
 * The relay must OWN its AMQPChannel exclusively: publisher-confirm mode is
 * channel-global state, and any other publisher on the same channel corrupts
 * the confirm bookkeeping. One relay process, one dedicated channel.
 *
 * Kafka/SQS get their own relay classes — there is no shared relay interface.
 */
final class AmqpRelay
{
    private bool $laneAcquired = false;

    private bool $confirmsEnabled = false;

    private bool $shouldStop = false;

    private readonly Backoff $backoff;

    public function __construct(
        private readonly OutboxStore $outbox,
        private readonly AMQPChannel $amqp,
        private readonly AmqpPublishConfig $publish,
        private readonly string $contentType,
        private readonly string $lane = 'default',
        private readonly int $batchSize = 100,
        ?Backoff $backoff = null,
        private readonly ?ErrorHandler $errorHandler = null,
        private readonly int $idleSleepMs = 200,
        private readonly int $confirmTimeoutSec = 5,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?BrokerEvents $events = null,
    ) {
        $this->backoff = $backoff ?? Backoff::exponential(initialDelayMs: 1_000, maxDelayMs: 300_000);
    }

    /** Long-running entrypoint; stops on SIGTERM/SIGINT when pcntl is available. */
    public function run(): void
    {
        $this->registerSignalHandlers();

        try {
            while (!$this->shouldStop) {
                if ($this->drainOnce() === 0) {
                    usleep(IdleSleep::micros($this->idleSleepMs, intdiv($this->idleSleepMs, 4)));
                }
            }
        } finally {
            $this->shutdown();
        }
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    /** Release the owned lane so a standby relay takes over at once. Idempotent. */
    public function shutdown(): void
    {
        if ($this->laneAcquired) {
            $this->outbox->releaseLane($this->lane);
            $this->laneAcquired = false;
        }
    }

    /** One pass over the owned lane. @return int rows published and deleted */
    public function drainOnce(): int
    {
        if (!$this->laneAcquired) {
            $this->laneAcquired = $this->outbox->tryAcquireLane($this->lane);
            if (!$this->laneAcquired) {
                return 0; // owned elsewhere — retry next tick so a standby can take over
            }
        }

        $prefix = $this->outbox->lanePrefix($this->lane, $this->batchSize);

        // Head-of-line: if the first row is backing off, the whole lane waits.
        if ($prefix === [] || $prefix[0]->availableAt > EpochMillis::now()) {
            return 0;
        }

        try {
            $this->publishBatch($prefix);
        } catch (Throwable $error) {
            $this->backOffHead($prefix[0], $error);

            return 0;
        }

        $this->outbox->deleteBatch(array_map(static fn (OutboxRecord $record): string => $record->id, $prefix));

        $this->events?->record(BrokerEvents::RELAYED, [
            'lane' => $this->lane,
            'count' => count($prefix),
        ]);

        return count($prefix);
    }

    /** @param non-empty-list<OutboxRecord> $batch */
    private function publishBatch(array $batch): void
    {
        if ($this->publish->publisherConfirms && !$this->confirmsEnabled) {
            $this->amqp->confirm_select();
            $this->confirmsEnabled = true;
        }

        foreach ($batch as $record) {
            // Explode the metadata column into individual x-message-* headers
            // (E7); produce-time headers ride alongside. array_merge order puts
            // the envelope headers last, so they win on any key collision. The
            // relay never parses the body.
            $headers = array_merge($record->headers, MetadataHeader::explode($record->metadata));

            $message = new AMQPMessage($record->body, [
                'content_type' => $this->contentType,
                'message_id' => $record->id,
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => new AMQPTable($headers),
            ]);
            // Route by message name (the message type). Per-key routing is a
            // postponed lane mode (see AmqpPublishConfig); no knob here today.
            $this->amqp->basic_publish($message, $this->publish->exchange, $record->messageName());
        }

        if ($this->publish->publisherConfirms) {
            // One confirm wait for the whole batch.
            $this->amqp->wait_for_pending_acks($this->confirmTimeoutSec);
        }
    }

    private function backOffHead(OutboxRecord $head, Throwable $error): void
    {
        $attempt = $head->attempts + 1;
        $delayMs = $this->backoff->delayForAttempt($attempt);
        $this->outbox->scheduleRetry($head->id, EpochMillis::now() + $delayMs);

        $context = [
            'lane' => $this->lane,
            'message_id' => $head->id,
            'message_name' => $head->messageName(),
            'attempt' => $attempt,
            'retry_in_ms' => $delayMs,
        ];
        $this->logger->warning('Relay publish failed; lane backing off', [
            'exception' => $error,
        ] + $context);
        $this->errorHandler?->handle($error, $context);
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
