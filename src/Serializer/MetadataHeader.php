<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

/**
 * The `x-message-*` envelope-header convention shared by the relay (write) and
 * both deserializers + consumer triage (read). The envelope is the outbox
 * row's own columns (id, message_name, created_at); at relay production it
 * becomes one native transport header per field — `x-message-id`,
 * `x-message-name`, `x-created-at`. There is no single JSON-blob header.
 */
final class MetadataHeader
{
    public const string MESSAGE_ID = 'x-message-id';
    public const string MESSAGE_NAME = 'x-message-name';
    public const string CREATED_AT = 'x-created-at';

    /**
     * Build the envelope headers of one message. The relay merges these OVER
     * the produce-time headers, so the envelope is authoritative on key
     * collisions.
     *
     * @return array{x-message-id: string, x-message-name: string, x-created-at: int}
     */
    public static function envelope(string $messageId, string $messageName, int $createdAt): array
    {
        return [
            self::MESSAGE_ID => $messageId,
            self::MESSAGE_NAME => $messageName,
            self::CREATED_AT => $createdAt,
        ];
    }

    /**
     * Read the envelope triple back from the individual `x-message-*` headers
     * (E7). `x-created-at` arrives as an int (AMQP int64). Extra `x-<key>` fields
     * stay on `IncomingMessage::$headers` but are not required to build the envelope.
     *
     * @param array<string, mixed> $headers transport headers
     *
     * @return array{message_id: string, message_name: string, created_at: int}
     */
    public static function parse(array $headers): array
    {
        $messageId = $headers[self::MESSAGE_ID] ?? null;
        $messageName = $headers[self::MESSAGE_NAME] ?? null;
        $createdAt = $headers[self::CREATED_AT] ?? null;

        if (!is_string($messageId) || !is_string($messageName) || !is_int($createdAt)) {
            throw new MalformedMessage(
                'Delivery requires '.self::MESSAGE_ID.' (string), '.self::MESSAGE_NAME.' (string) and '.self::CREATED_AT.' (epoch ms int) headers',
            );
        }

        return [
            'message_id' => $messageId,
            'message_name' => $messageName,
            'created_at' => $createdAt,
        ];
    }
}
