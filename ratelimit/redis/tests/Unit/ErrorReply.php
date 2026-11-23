<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\RateLimit\Redis\Tests\Unit;

/** An error reply of the server, for the recording clients. */
final readonly class ErrorReply
{
    public function __construct(
        public string $message,
    ) {}
}
