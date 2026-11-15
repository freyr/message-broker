<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

use Freyr\MessageBroker\Consumer\IncomingMessage;

/**
 * Consumer-side stage 1 → 2: transport bytes into the transport-agnostic
 * IncomingMessage. JsonDeserializer is the shipped implementation.
 */
interface Deserializer
{
    /** @param array<string, mixed> $headers */
    public function deserialize(string $bytes, array $headers = []): IncomingMessage;
}
