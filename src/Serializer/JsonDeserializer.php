<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

use Freyr\MessageBroker\Consumer\IncomingMessage;
use JsonException;

final readonly class JsonDeserializer implements Deserializer
{
    /** @param array<string, mixed> $headers */
    public function deserialize(string $bytes, array $headers = []): IncomingMessage
    {
        try {
            $document = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new MalformedMessage("Body is not valid JSON: {$error->getMessage()}", previous: $error);
        }

        if (!is_array($document)) {
            throw new MalformedMessage('Body must be a JSON object with metadata and payload sections');
        }

        $metadata = $document['metadata'] ?? null;
        $payload = $document['payload'] ?? null;
        if (!is_array($metadata) || !is_array($payload)) {
            throw new MalformedMessage('Body must contain metadata and payload object sections');
        }

        /** @var array<string, mixed> $payload JSON objects decode to string-keyed arrays */
        $messageId = $metadata['message_id'] ?? null;
        $messageName = $metadata['message_name'] ?? null;
        $createdAt = $metadata['created_at'] ?? null;
        if (!is_string($messageId) || !is_string($messageName) || !is_int($createdAt)) {
            throw new MalformedMessage(
                'Metadata must contain message_id (string), message_name (string) and created_at (epoch ms int)',
            );
        }

        return new IncomingMessage(
            messageId: $messageId,
            messageName: $messageName,
            createdAt: $createdAt,
            payload: $payload,
            headers: $headers,
        );
    }
}
