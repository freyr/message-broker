<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Consumer;

use Closure;

/**
 * One explicit message_name → (class, handler) mapping.
 *
 * The consumer-side class is a plain DTO owned by the consuming service —
 * it mirrors the payload schema and does NOT extend Message. Envelope data
 * (id, name, createdAt, headers) rides on IncomingMessage.
 *
 * Handler signature: function (object $message, IncomingMessage $envelope): void
 */
final readonly class Binding
{
    public function __construct(
        public string $class,
        public Closure $handler,
    ) {}
}
