<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures;

use Freyr\MessageBroker\DeadLetter\DeadLetter;

final class DeadLetterFactory
{
    /** @param array<string, mixed> $headers */
    public static function make(
        string $id,
        string $messageName = 'order.placed',
        string $source = 'orders_q',
        string $errorMessage = 'boom',
        int $failedAt = 1_750_000_000_123,
        ?int $replayedAt = null,
        ?string $body = null,
        array $headers = [],
        string $messageId = '',
        bool $replayable = true,
    ): DeadLetter {
        $messageId = $messageId === '' ? "m-{$id}" : $messageId;

        return new DeadLetter(
            id: $id,
            source: $source,
            messageId: $messageId,
            messageName: $messageName,
            body: $body ?? self::document($messageId, $messageName),
            headers: $headers,
            errorClass: 'RuntimeException',
            errorMessage: $errorMessage,
            errorTrace: '#0 trace-line',
            attempts: 5,
            failedAt: $failedAt,
            replayedAt: $replayedAt,
            replayable: $replayable,
        );
    }

    /** A replayable wire document, as a consumer stores it in the DLQ. */
    public static function document(string $messageId, string $messageName): string
    {
        return (string) json_encode([
            'metadata' => [
                'message_id' => $messageId,
                'message_name' => $messageName,
                'created_at' => 1_750_000_000_000,
            ],
            'payload' => [
                'order_id' => 'o-1',
            ],
        ]);
    }
}
