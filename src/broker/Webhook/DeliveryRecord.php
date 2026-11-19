<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

use InvalidArgumentException;

/**
 * One row of the webhook_deliveries table: one message for one subscription.
 * All rows of one message have the same messageId, which is the ID that the
 * receiver gets; each row has its own id. The lane is the subscription id.
 * `body` holds the final bytes of the request body, the same bytes that the
 * outbox stores for the message. The row holds no URL and no secret.
 *
 * The constructor enforces the column limits so a bad lane or name fails
 * with a clear error, not a database truncation error.
 */
final readonly class DeliveryRecord
{
    public const int MAX_LANE_LENGTH = 64;
    public const int MAX_NAME_LENGTH = 255;

    /**
     * @param array<string, mixed> $headers produce-time headers
     */
    public function __construct(
        public string $id,                // UUIDv7 of this row
        public string $messageId,         // UUIDv7 of the message; the same for each row of one message
        public string $lane,              // subscription id
        public string $messageName,
        public string $body,              // final bytes of the request body
        public array $headers,
        public int $createdAt,            // epoch milliseconds; the partition key, and the date that abandon() compares
        public int $attempts = 0,         // requests that got a result
        public ?int $availableAt = null,  // epoch ms; null = due now (insert path)
    ) {
        self::assertLength('lane', $lane, self::MAX_LANE_LENGTH);
        self::assertLength('message name', $messageName, self::MAX_NAME_LENGTH);
    }

    private static function assertLength(string $field, string $value, int $max): void
    {
        $length = mb_strlen($value);
        if ($length === 0 || $length > $max) {
            throw new InvalidArgumentException(
                sprintf('Delivery %s must be 1 to %d characters, got %d: "%s"', $field, $max, $length, $value),
            );
        }
    }
}
