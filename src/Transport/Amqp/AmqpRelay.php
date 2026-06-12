<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp;

use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\OutboxStore;
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
 *   acquire lane (advisory lock — one relay per lane) →
 *   read contiguous prefix → publish in id order with confirms →
 *   delete each row; first failure backs off the head and stops the pass
 *
 * No DLQ on this path: a committed outbox row is publishable by definition
 * (validated at produce time), so failures are transient — retry with
 * backoff indefinitely; a long-blocked lane is an operational alert
 * surfaced through the error handler, never data loss.
 *
 * Kafka/SQS get their own relay classes — there is no shared relay interface.
 */
final class AmqpRelay
{
    private ?bool $laneAcquired = null;

    private bool $confirmsEnabled = false;

    public function __construct(
        private readonly OutboxStore $outbox,
        private readonly AMQPChannel $amqp,
        private readonly AmqpPublishConfig $publish,
        private readonly Serializer $serializer,
        private readonly string $lane = 'default',
        private readonly int $batchSize = 100,
        private readonly int $initialBackoffMs = 1_000,
        // TODO slice 1: ErrorHandler injection (logging/metrics/alerting).
    ) {}

    public function run(): void
    {
        // TODO slice 1: pcntl signal handling, idle backoff.
        // @phpstan-ignore while.alwaysTrue (long-running worker loop, exits via signals in slice 1)
        while (true) {
            $this->drainOnce();
        }
    }

    /** One pass over the owned lane. @return int rows published */
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

        $published = 0;
        foreach ($prefix as $record) {
            if (!$this->publishOne($record)) {
                break; // first failure stops the lane — nothing overtakes
            }
            ++$published;
        }

        return $published;
    }

    private function publishOne(OutboxRecord $record): bool
    {
        try {
            if ($this->publish->publisherConfirms && !$this->confirmsEnabled) {
                $this->amqp->confirm_select();
                $this->confirmsEnabled = true;
            }

            $message = new AMQPMessage($this->serializer->serialize($record->body), [
                'content_type' => $this->serializer->contentType(),
                'message_id' => $record->id,
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => new AMQPTable($record->headers),
            ]);

            $routingKey = str_replace('{message_name}', $record->messageName, $this->publish->routingKeyTemplate);
            $this->amqp->basic_publish($message, $this->publish->exchange, $routingKey);

            if ($this->publish->publisherConfirms) {
                $this->amqp->wait_for_pending_acks(3);
            }

            $this->outbox->delete($record->id);

            return true;
        } catch (Throwable) {
            // Transient by definition — back off the head, block the lane.
            // TODO slice 1: exponential backoff from $record->attempts, error
            // handler hook, batched drain (one confirm wait + chunked delete —
            // ~30× throughput, see outbox-relay-throughput research note).
            $this->outbox->scheduleRetry($record->id, EpochMillis::now() + $this->initialBackoffMs);

            return false;
        }
    }
}
