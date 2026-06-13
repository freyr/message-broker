<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

use RuntimeException;

/**
 * The `x-message-*` envelope-header convention shared by the relay (write) and
 * both deserializers + consumer triage (read), E7. The envelope is KEPT as the
 * outbox `metadata` JSON column; at relay production it is EXPLODED into one
 * native transport header per key — `x-message-id`, `x-message-name`,
 * `x-created-at`, plus any future field as `x-<key>` (underscores → dashes).
 * This is the slice-2 individual-header model, retained and made symmetric for
 * JSON — there is no single JSON-blob header.
 */
final class MetadataHeader
{
    public const string MESSAGE_ID = 'x-message-id';
    public const string MESSAGE_NAME = 'x-message-name';
    public const string CREATED_AT = 'x-created-at';

    /**
     * Explode the outbox `metadata` bag into individual transport headers (E7):
     * one `x-<key>` key-value pair per field. Scalar values only — future
     * fields auto-map with no relay change. The relay merges these OVER the
     * produce-time headers, so the envelope is authoritative on key collisions.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array<string, int|string>
     */
    public static function explode(array $metadata): array
    {
        $headers = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($value) && !is_int($value)) {
                throw new RuntimeException("Metadata field '{$key}' must be string|int to ride a transport header");
            }

            $headers['x-'.str_replace('_', '-', $key)] = $value;
        }

        return $headers;
    }

    /**
     * Read the envelope triple back from the individual `x-message-*` headers
     * (E7). Matches the shipped slice-2 deserializer exactly: `x-created-at`
     * must arrive as an int (AMQPTable int64 `l`). Extra `x-<key>` fields stay
     * on `IncomingMessage::$headers` but are not required to build the envelope.
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
