<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

use Freyr\MessageBroker\Consumer\IncomingMessage;

/**
 * Consumer-side stage 1 → 2: transport bytes into the transport-agnostic
 * IncomingMessage. Content type is explicit consumer configuration — a
 * consumer is TOLD its queue carries JSON or Avro, never sniffs.
 */
interface Deserializer
{
    /** @param array<string, mixed> $headers */
    public function deserialize(string $bytes, array $headers = []): IncomingMessage;
}
