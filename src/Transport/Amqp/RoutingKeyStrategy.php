<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp;

use Freyr\MessageBroker\Outbox\OutboxRecord;

/**
 * What the AMQP relay uses as the publish routing key.
 *
 * MessageName — the message type (e.g. 'order.placed'). Default; for topic/
 *   direct/fanout routing where queues bind by type.
 * MessageKey  — the per-message ordering key (e.g. an order id). ONLY meaningful
 *   with an x-consistent-hash exchange + a single active consumer (SAC): that
 *   pairing hashes same-key messages to one in-order queue, approximating
 *   per-key FIFO. The consistent-hash lane mode itself is future work.
 */
enum RoutingKeyStrategy
{
    case MessageName;
    case MessageKey;

    public function resolve(OutboxRecord $record): string
    {
        return match ($this) {
            self::MessageName => $record->messageName(),
            self::MessageKey => $record->key,
        };
    }
}
