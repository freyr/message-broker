<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Consumer;

/**
 * Consumer pipeline hand-off: the broker deserializes a delivery into an
 * IncomingMessage (stage 2) and hands it to the application's handling layer
 * through this single method. Denormalization (payload → a userland command)
 * and routing live behind this seam, in a separate component (a command bus
 * such as Symfony Messenger), NOT in the broker.
 *
 * dispatch() is called INSIDE the consumer's PDO transaction, after the dedup
 * INSERT. An implementation that writes through the SAME PDO connection commits
 * atomically with the dedup row (exactly-once); an implementation that
 * dispatches asynchronously or to a different datastore is at-least-once and
 * MUST be idempotent. A thrown exception rolls back the transaction and routes
 * the delivery through the consumer's retry/DLQ policy.
 */
interface MessageDispatcher
{
    public function dispatch(IncomingMessage $message): void;
}
