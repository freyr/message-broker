<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp;

use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * AMQP publish mechanics shared by AmqpRelay (ordered) and CompetingAmqpRelay:
 * explode metadata into individual x-message-* headers, publish the batch,
 * ONE confirm wait covering the whole batch. The owning relay must hold its
 * AMQPChannel exclusively: publisher-confirm mode is channel-global state,
 * and any other publisher on the same channel corrupts the confirm
 * bookkeeping. One relay process, one dedicated channel, one publisher.
 */
final class AmqpMessagePublisher
{
    private bool $confirmsEnabled = false;

    public function __construct(
        private readonly AMQPChannel $amqp,
        private readonly AmqpPublishConfig $publish,
        private readonly string $contentType,
        private readonly int $confirmTimeoutSec = 5,
    ) {}

    /** @param non-empty-list<OutboxRecord> $batch */
    public function publishBatch(array $batch): void
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
}
