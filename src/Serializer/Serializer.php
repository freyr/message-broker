<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

/**
 * Relay-side: encodes the canonical wire document (Message::wire() shape)
 * into transport bytes. The outbox always stores the JSON document; the
 * wire format is a per-relay decision (JSON now, raw/Avro later).
 *
 * Deliberately separate from Deserializer — no false symmetry.
 */
interface Serializer
{
    /** @param array{metadata: array<string, mixed>, payload: array<string, mixed>} $wire */
    public function serialize(array $wire): string;

    /** e.g. 'application/json', 'avro/binary' */
    public function contentType(): string;
}
