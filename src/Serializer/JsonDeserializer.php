<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

use Freyr\MessageBroker\Consumer\IncomingMessage;

final readonly class JsonDeserializer implements Deserializer
{
    public function deserialize(string $bytes, array $headers = []): IncomingMessage
    {
        $document = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);

        // TODO slice 1: validate the two-section shape, fail with a dedicated
        // exception type (malformed messages dead-letter immediately, no retry).
        return new IncomingMessage(
            messageId: $document['metadata']['message_id'],
            messageName: $document['metadata']['message_name'],
            createdAt: $document['metadata']['created_at'],
            payload: $document['payload'],
            headers: $headers,
        );
    }
}
