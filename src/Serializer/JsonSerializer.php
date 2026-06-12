<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

final readonly class JsonSerializer implements Serializer
{
    public function serialize(array $wire): string
    {
        return json_encode($wire, JSON_THROW_ON_ERROR);
    }

    public function contentType(): string
    {
        return 'application/json';
    }
}
