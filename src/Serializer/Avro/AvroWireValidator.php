<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer\Avro;

use Apache\Avro\Datum\AvroIOBinaryEncoder;
use Apache\Avro\Datum\AvroIODatumWriter;
use Apache\Avro\IO\AvroStringIO;
use Freyr\MessageBroker\Serializer\WireValidator;

/**
 * Encode-and-discard against the committed local schema (~6.5 µs, see the
 * avro-encode-overhead research note): full Avro conformance at produce
 * time with zero registry involvement (spec A3).
 */
final class AvroWireValidator implements WireValidator
{
    /** @var array<string, AvroIODatumWriter> */
    private array $writers = [];

    public function __construct(
        private readonly FileSchemaStore $schemas,
    ) {}

    public function assertPublishable(array $wire): void
    {
        $metadata = $wire['metadata'] ?? null;
        $payload = $wire['payload'] ?? null;
        if (!is_array($metadata) || !is_array($payload)) {
            throw new \InvalidArgumentException('Wire document must contain metadata and payload sections');
        }

        // Mirror AvroSerializer's full metadata contract: if this validator
        // passes, the lane's serializer must be able to encode the document.
        $messageName = $metadata['message_name'] ?? null;
        $messageId = $metadata['message_id'] ?? null;
        $createdAt = $metadata['created_at'] ?? null;
        if (!is_string($messageName) || !is_string($messageId) || !is_int($createdAt)) {
            throw new \InvalidArgumentException(
                'Wire metadata must contain message_name (string), message_id (string) and created_at (epoch ms int)',
            );
        }

        $writer = $this->writers[$messageName]
            ??= new AvroIODatumWriter($this->schemas->schemaFor($messageName));

        try {
            $writer->write($payload, new AvroIOBinaryEncoder(new AvroStringIO()));
        } catch (\Throwable $error) {
            throw new \InvalidArgumentException(
                "Payload for '{$messageName}' does not conform to its Avro schema: {$error->getMessage()}",
                previous: $error,
            );
        }
    }
}
