<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

/**
 * Produce-time poison prevention (D17): rejects a wire document that the
 * lane's serializer could not encode, INSIDE the application's transaction,
 * so a non-publishable message never commits to the outbox. The relay can
 * then treat every publish failure as transient and retry forever.
 *
 * Wired per OutboxProducer (= per lane); must match the lane's relay-side
 * Serializer — a userland configuration responsibility. Throws
 * InvalidArgumentException when the document cannot be published.
 */
interface WireValidator
{
    /** @param array<string, mixed> $wire the Message::wire() document */
    public function assertPublishable(array $wire): void;
}
