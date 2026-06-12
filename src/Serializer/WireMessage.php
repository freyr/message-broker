<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

/**
 * What a Serializer hands to a relay for publishing: the body bytes plus
 * any transport headers the wire format itself requires (e.g. Avro's
 * x-message-* metadata headers — the Avro body carries the payload record
 * only). Serializer-contributed headers win over produce-time headers.
 */
final readonly class WireMessage
{
    /** @param array<string, int|string> $headers */
    public function __construct(
        public string $bytes,
        public string $contentType,
        public array $headers = [],
    ) {}
}
