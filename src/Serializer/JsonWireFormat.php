<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

final readonly class JsonWireFormat implements WireFormat
{
    public const string CONTENT_TYPE = 'application/json';

    public function contentType(): string
    {
        return self::CONTENT_TYPE;
    }

    /** @param array<string, mixed> $payload */
    public function encode(string $messageName, array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
