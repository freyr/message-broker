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
        $meta = MetadataHeader::parse($headers);

        try {
            $payload = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new MalformedMessage("Body is not valid JSON: {$error->getMessage()}", previous: $error);
        }

        if (!is_array($payload)) {
            throw new MalformedMessage('JSON body must decode to a payload object');
        }

        /** @var array<string, mixed> $payload */
        return new IncomingMessage(
            messageId: $meta['message_id'],
            messageName: $meta['message_name'],
            createdAt: $meta['created_at'],
            payload: $payload,
            headers: $headers,
        );
    }
}
