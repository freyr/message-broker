<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use Symfony\Component\Messenger\Envelope;

/**
 * Publish an OutboxMessage to an external transport.
 *
 * The envelope is the single source of truth. It contains:
 * - The OutboxMessage instance (unwrapped event)
 * - MessageIdStamp (stable UUID v7, survives redelivery)
 * - MessageNameStamp (semantic name from #[MessageName])
 *
 * Implementations are responsible for:
 * - Extracting MessageNameStamp to derive transport-specific routing
 * - Creating transport-specific stamps (AmqpStamp, SqsStamp, etc.)
 * - Resolving and calling the appropriate SenderInterface
 */
interface OutboxPublisherInterface
{
    public function publish(Envelope $envelope): void;
}
