<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Amqp;

use Freyr\MessageBroker\Outbox\OutboxPublisher;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * AMQP implementation of the {@see OutboxPublisher} port: add the x-message-*
 * envelope headers from the outbox row's columns (id, message_name,
 * created_at), publish the batch, ONE confirm wait covering the whole batch.
 * A broker nack is reported per record; an ack covers the rest. AMQP has no
 * batch limit, so one claim is one batch and one confirm wait.
 *
 * Channel ownership: the owning relay must hold its AMQPChannel exclusively
 * and run on its own database connection. Publisher-confirm mode is
 * channel-global state, and any other publisher on the same channel corrupts
 * the confirm bookkeeping.
 *
 * Failure handling: a nack backs off only the nacked rows and the channel
 * stays usable. A channel, connection or confirm-timeout failure throws; the
 * relay then backs off the rows of the call and stops the worker (crash-only),
 * and the supervisor restarts it with a fresh connection.
 *
 * Messages are published without the mandatory flag: a message that no queue
 * binding matches is dropped by the broker and confirmed. That is the design —
 * a message nobody subscribes to is not a failure.
 */
final class AmqpMessagePublisher implements OutboxPublisher
{
    private bool $confirmsEnabled = false;

    /** @var list<string> ids of the records the broker nacked in the current batch */
    private array $nacked = [];

    public function __construct(
        private readonly AMQPChannel $amqp,
        private readonly AmqpPublishConfig $publish,
        private readonly string $contentType,
        private readonly int $confirmTimeoutSec = 5,
    ) {}

    /**
     * Publish the batch and wait for its confirms. Throws when the channel or
     * the connection fails, or when the confirm wait times out.
     *
     * @param non-empty-list<OutboxRecord> $batch
     *
     * @return list<string> ids of the records the broker nacked (empty without publisher confirms)
     */
    public function publishBatch(array $batch): array
    {
        if ($this->publish->publisherConfirms && !$this->confirmsEnabled) {
            $this->amqp->confirm_select();
            $this->amqp->set_nack_handler(function (AMQPMessage $message): void {
                $id = $message->get('message_id');
                if (is_string($id)) {
                    $this->nacked[] = $id;
                }
            });
            $this->confirmsEnabled = true;
        }
        $this->nacked = [];

        foreach ($batch as $record) {
            // The envelope (id, name, created_at) becomes individual
            // x-message-* headers; produce-time headers ride alongside.
            // array_merge order puts the envelope headers last, so they win on
            // any key collision. The relay never parses the body.
            $headers = array_merge(
                $record->headers,
                MetadataHeader::envelope($record->id, $record->messageName, $record->createdAt),
            );

            $message = new AMQPMessage($record->body, [
                'content_type' => $this->contentType,
                'message_id' => $record->id,
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => new AMQPTable($headers),
            ]);
            // Route by message name (the message type).
            $this->amqp->basic_publish($message, $this->publish->exchange, $record->messageName);
        }

        if ($this->publish->publisherConfirms) {
            // One confirm wait for the whole batch; the nack handler collects rejections.
            $this->amqp->wait_for_pending_acks($this->confirmTimeoutSec);
        }

        return $this->nacked;
    }

    /** AMQP has no batch limit: one claim is one batch. */
    public function maxBatchSize(): int
    {
        return PHP_INT_MAX;
    }
}
