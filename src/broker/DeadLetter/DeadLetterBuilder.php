<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DeadLetter;

use Freyr\MessageBroker\Consumer\Delivery;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Serializer\MalformedMessage;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Builds a DeadLetter from a delivery, in one of two forms:
 *
 *  - canonical: the message deserialized. The body is the canonical document
 *    {"metadata": ..., "payload": ...}, readable in dlq:show and replayable
 *    through the outbox whatever the wire format of the queue is.
 *  - raw: the message did not deserialize. The body is the exact wire bytes,
 *    and the dead letter is not replayable. The identity comes from the
 *    x-message-* headers, else the message id of the transport, else a new
 *    'unknown-<UUID v7>', so that two messages with no id never share a row.
 *
 * The error comes from a Throwable (the consumer) or from explicit fields with
 * an empty trace (the import of a message that the transport moved: no
 * handler ran, so no Throwable exists). Each dead letter gets a new id.
 *
 * A core class, not a port: DeliveryProcessor and DeadLetterImporter use it,
 * and a user passes a new instance to DeadLetterImporter.
 */
final readonly class DeadLetterBuilder
{
    /** The canonical form, replayable, with the error, the time and the source of the consumer. */
    public function canonical(Delivery $delivery, IncomingMessage $incoming, Throwable $error): DeadLetter
    {
        return DeadLetter::fromFailure(
            source: $delivery->source,
            messageId: $incoming->messageId,
            messageName: $incoming->messageName,
            body: self::document($incoming),
            headers: $incoming->headers,
            error: $error,
            attempts: $delivery->attempt,
            replayable: true,
        );
    }

    /** The raw form, not replayable, with the error, the time and the source of the consumer. */
    public function raw(Delivery $delivery, Throwable $error): DeadLetter
    {
        [$messageId, $messageName] = self::rawIdentity($delivery);

        return DeadLetter::fromFailure(
            source: $delivery->source,
            messageId: $messageId,
            messageName: $messageName,
            body: $delivery->body,
            headers: $delivery->headers,
            error: $error,
            attempts: $delivery->attempt,
            replayable: false,
        );
    }

    /**
     * The canonical form with explicit error fields and an empty trace.
     *
     * @param int $failedAt epoch milliseconds
     */
    public function canonicalWithError(
        Delivery $delivery,
        IncomingMessage $incoming,
        string $source,
        string $errorClass,
        string $errorMessage,
        int $failedAt,
        bool $replayable,
    ): DeadLetter {
        return new DeadLetter(
            id: Uuid::v7()->toString(),
            source: $source,
            messageId: $incoming->messageId,
            messageName: $incoming->messageName,
            body: self::document($incoming),
            headers: $incoming->headers,
            errorClass: $errorClass,
            errorMessage: $errorMessage,
            errorTrace: '',
            attempts: $delivery->attempt,
            failedAt: $failedAt,
            replayable: $replayable,
        );
    }

    /**
     * The raw form with explicit error fields and an empty trace; not replayable.
     *
     * @param int $failedAt epoch milliseconds
     */
    public function rawWithError(
        Delivery $delivery,
        string $source,
        string $errorClass,
        string $errorMessage,
        int $failedAt,
    ): DeadLetter {
        [$messageId, $messageName] = self::rawIdentity($delivery);

        return new DeadLetter(
            id: Uuid::v7()->toString(),
            source: $source,
            messageId: $messageId,
            messageName: $messageName,
            body: $delivery->body,
            headers: $delivery->headers,
            errorClass: $errorClass,
            errorMessage: $errorMessage,
            errorTrace: '',
            attempts: $delivery->attempt,
            failedAt: $failedAt,
            replayable: false,
        );
    }

    private static function document(IncomingMessage $incoming): string
    {
        return json_encode([
            'metadata' => [
                'message_name' => $incoming->messageName,
                'message_id' => $incoming->messageId,
                'created_at' => $incoming->createdAt,
            ],
            'payload' => $incoming->payload,
            // INVALID_UTF8_SUBSTITUTE: substitution preferred over a failed
            // dead-letter write.
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** @return array{string, string} the message id and the message name of a raw dead letter */
    private static function rawIdentity(Delivery $delivery): array
    {
        try {
            $meta = MetadataHeader::parse($delivery->headers);

            return [$meta['message_id'], $meta['message_name']];
        } catch (MalformedMessage) {
            return [$delivery->fallbackMessageId ?? 'unknown-'.Uuid::v7()->toString(), 'unknown'];
        }
    }
}
