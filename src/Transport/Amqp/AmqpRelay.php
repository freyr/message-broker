<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp;

use Freyr\MessageBroker\ErrorHandler;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Serializer\Serializer;
use Freyr\MessageBroker\Time\EpochMillis;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
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
    private ?bool $laneAcquired = null;

    private bool $confirmsEnabled = false;

    private bool $shouldStop = false;

    private readonly Backoff $backoff;

    public function __construct(
        private readonly OutboxStore $outbox,
        private readonly AMQPChannel $amqp,
        private readonly AmqpPublishConfig $publish,
        private readonly Serializer $serializer,
        private readonly string $lane = 'default',
        private readonly int $batchSize = 100,
        ?Backoff $backoff = null,
        private readonly ?ErrorHandler $errorHandler = null,
        private readonly int $idleSleepMs = 200,
        private readonly int $confirmTimeoutSec = 5,
    ) {
        $this->backoff = $backoff ?? Backoff::exponential(initialDelayMs: 1_000, maxDelayMs: 300_000);
    }

    /** Long-running entrypoint; stops on SIGTERM/SIGINT when pcntl is available. */
    public function run(): void
    {
        $this->registerSignalHandlers();

        while (!$this->shouldStop) {
            if ($this->drainOnce() === 0) {
                usleep($this->idleSleepMs * 1_000);
            }
        }
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    /** One pass over the owned lane. @return int rows published and deleted */
    public function drainOnce(): int
    {
        $this->laneAcquired ??= $this->outbox->tryAcquireLane($this->lane);
        if (!$this->laneAcquired) {
            return 0; // another relay owns this lane
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
            $wire = $this->serializer->serialize($record->body);
            $message = new AMQPMessage($wire->bytes, [
                'content_type' => $wire->contentType,
                'message_id' => $record->id,
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => new AMQPTable(array_merge($record->headers, $wire->headers)),
            ]);
            $routingKey = str_replace('{message_name}', $record->messageName, $this->publish->routingKeyTemplate);
            $this->amqp->basic_publish($message, $this->publish->exchange, $routingKey);
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

        $this->errorHandler?->handle($error, [
            'lane' => $this->lane,
            'message_id' => $head->id,
            'message_name' => $head->messageName,
            'attempt' => $attempt,
            'retry_in_ms' => $delayMs,
        ]);
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
