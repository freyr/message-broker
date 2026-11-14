<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

/**
 * Produce-side encoder (E2): turns Message::wire()['payload'] into the final
 * wire body bytes stored verbatim in the outbox `body` column. The encode is
 * also the poison check (E5) — a non-publishable payload throws here, inside
 * the application's transaction, so nothing commits. The relay pumps the
 * bytes unchanged; content_type is a global constant.
 *
 * Deliberately separate from Deserializer — no false symmetry.
 */
interface WireFormat
{
    /** The global transport content type (e.g. application/json, avro/binary). */
    public function contentType(): string;

    /**
     * @param array<string, mixed> $payload Message::wire()['payload']
     *
     * @return string final wire body bytes (payload only)
     */
    public function encode(string $messageName, array $payload): string;
}
