<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use InvalidArgumentException;

/**
 * One row of the outbox_messages table — what the producer writes and what a
 * relay publishes. `body` holds the FINAL wire bytes (payload only, already
 * encoded at produce time). The envelope is the row's own columns — id,
 * message_name, created_at — which the relay turns into x-message-* headers.
 *
 * The constructor enforces the column limits so a bad lane or name fails
 * with a clear error, not a database truncation error.
 */
final readonly class OutboxRecord
{
    public const int MAX_LANE_LENGTH = 64;
    public const int MAX_NAME_LENGTH = 255;

    /**
     * @param array<string, mixed> $headers produce-time transport headers (e.g. correlation_id)
     */
    public function __construct(
        public string $id,                // UUIDv7, from Message
        public string $lane,              // named drain of the outbox table
        public string $messageName,       // message type; also the AMQP routing key
        public string $body,              // final wire bytes, payload only
        public array $headers,
        public int $createdAt,            // epoch milliseconds; the partition key
        public int $attempts = 0,
        public ?int $availableAt = null,  // epoch ms; null = available now (insert path)
    ) {
        self::assertLength('lane', $lane, self::MAX_LANE_LENGTH);
        self::assertLength('message name', $messageName, self::MAX_NAME_LENGTH);
    }

    private static function assertLength(string $field, string $value, int $max): void
    {
        $length = mb_strlen($value);
        if ($length === 0 || $length > $max) {
            throw new InvalidArgumentException(
                sprintf('Outbox %s must be 1 to %d characters, got %d: "%s"', $field, $max, $length, $value),
            );
        }
    }
}
