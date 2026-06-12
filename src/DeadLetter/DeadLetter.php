<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DeadLetter;

use Throwable;

/**
 * One row of the dead_letters table — the uniform, database-backed DLQ
 * shared by all transports (design decision D8).
 *
 * CONSUMER-SIDE ONLY (D17): only consumption failures dead-letter. The
 * outbox/relay path never does — it preserves ordering and retries forever.
 */
final readonly class DeadLetter
{
    public function __construct(
        public string $id,
        public string $source,        // queue / topic / lane it failed on
        public string $messageId,
        public string $messageName,
        public string $body,          // raw bytes as received / as stored
        public array $headers,
        public string $errorClass,
        public string $errorMessage,
        public string $errorTrace,
        public int $attempts,
        public int $failedAt,         // epoch milliseconds
        public ?int $replayedAt = null,
    ) {}

    public static function fromFailure(
        string $source,
        string $messageId,
        string $messageName,
        string $body,
        array $headers,
        Throwable $error,
        int $attempts,
    ): self {
        // TODO slice 1: id generation, Clock::now(), error chain flattening.
        throw new \LogicException('skeleton');
    }
}
