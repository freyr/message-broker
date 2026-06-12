<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

final readonly class JsonSerializer implements Serializer
{
    public function serialize(array $wire): WireMessage
    {
        return new WireMessage(bytes: json_encode($wire, JSON_THROW_ON_ERROR), contentType: 'application/json');
    }
}
