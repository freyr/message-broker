<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DeadLetter;

use Freyr\MessageBroker\BrokerHeader;
use JsonException;
use Symfony\Component\Uid\Uuid;

/**
 * The value of the header {@see BrokerHeader::DEAD_LETTER}: the error data of
 * a dead letter as one JSON object, which a transport sink adds to its message
 * and the import of a dead letter queue reads back.
 *
 *   {"id": string, "source": string, "message_id": string,
 *    "message_name": string, "error_class": string, "error_message": string,
 *    "error_trace": string, "attempts": int, "failed_at": int (epoch ms),
 *    "replayable": bool}
 *
 * encode() cuts the error message to 1024 bytes and the error trace to 16384
 * bytes at a UTF-8 character boundary, because all headers of an AMQP message
 * must fit one frame (131064 bytes). decode() never throws: a value that is
 * not a string, not a JSON object, has a key that is absent or of the wrong
 * type, or has an id that is not a UUID decodes to null. A key that the
 * decoder does not know is ignored, so a new key is an addition; a removal or
 * a type change of a key is a breaking change of the header.
 */
final class DeadLetterHeader
{
    public const int MAX_ERROR_MESSAGE_BYTES = 1024;

    public const int MAX_ERROR_TRACE_BYTES = 16384;

    private const array STRING_KEYS = [
        'id',
        'source',
        'message_id',
        'message_name',
        'error_class',
        'error_message',
        'error_trace',
    ];

    private const array INT_KEYS = ['attempts', 'failed_at'];

    public static function encode(DeadLetter $deadLetter): string
    {
        return json_encode([
            'id' => $deadLetter->id,
            'source' => $deadLetter->source,
            'message_id' => $deadLetter->messageId,
            'message_name' => $deadLetter->messageName,
            'error_class' => $deadLetter->errorClass,
            'error_message' => mb_strcut($deadLetter->errorMessage, 0, self::MAX_ERROR_MESSAGE_BYTES, 'UTF-8'),
            'error_trace' => mb_strcut($deadLetter->errorTrace, 0, self::MAX_ERROR_TRACE_BYTES, 'UTF-8'),
            'attempts' => $deadLetter->attempts,
            'failed_at' => $deadLetter->failedAt,
            'replayable' => $deadLetter->replayable,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array{
     *     id: string,
     *     source: string,
     *     message_id: string,
     *     message_name: string,
     *     error_class: string,
     *     error_message: string,
     *     error_trace: string,
     *     attempts: int,
     *     failed_at: int,
     *     replayable: bool,
     * }|null the ten values, or null when the value is not a valid header
     */
    public static function decode(mixed $value): ?array
    {
        if (!is_string($value)) {
            return null;
        }

        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $strings = [];
        foreach (self::STRING_KEYS as $key) {
            $item = $decoded[$key] ?? null;
            if (!is_string($item)) {
                return null;
            }
            $strings[$key] = $item;
        }
        $integers = [];
        foreach (self::INT_KEYS as $key) {
            $item = $decoded[$key] ?? null;
            if (!is_int($item)) {
                return null;
            }
            $integers[$key] = $item;
        }
        $replayable = $decoded['replayable'] ?? null;
        if (!is_bool($replayable) || !Uuid::isValid($strings['id'])) {
            return null;
        }

        return [
            'id' => $strings['id'],
            'source' => $strings['source'],
            'message_id' => $strings['message_id'],
            'message_name' => $strings['message_name'],
            'error_class' => $strings['error_class'],
            'error_message' => $strings['error_message'],
            'error_trace' => $strings['error_trace'],
            'attempts' => $integers['attempts'],
            'failed_at' => $integers['failed_at'],
            'replayable' => $replayable,
        ];
    }
}
