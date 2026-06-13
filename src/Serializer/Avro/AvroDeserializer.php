<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer\Avro;

use Apache\Avro\Datum\AvroIOBinaryDecoder;
use Apache\Avro\Datum\AvroIODatumReader;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Serializer\Deserializer;
use Freyr\MessageBroker\Serializer\MalformedMessage;
use Freyr\MessageBroker\Serializer\MetadataHeader;

/**
 * Consumer stage 1 → 2 for Avro queues: envelope from the x-* transport
 * headers, payload decoded with the writer schema fetched by the frame's
 * schema id (cached per id for the process lifetime).
 *
 * Failure taxonomy (spec A10): structural problems throw MalformedMessage
 * (→ DLQ, a malformed message never improves); registry failures
 * (RegistryUnavailable / SchemaNotFound) propagate untouched so the
 * delivery is requeued — never dead-lettered.
 */
final class AvroDeserializer implements Deserializer
{
    /** @var array<int, AvroIODatumReader> */
    private array $readers = [];

    public function __construct(
        private readonly SchemaRegistry $registry,
    ) {}

    /** @param array<string, mixed> $headers */
    public function deserialize(string $bytes, array $headers = []): IncomingMessage
    {
        $meta = MetadataHeader::parse($headers);

        $frame = ConfluentFrame::parse($bytes);

        // Transient registry failures intentionally propagate from here (A10).
        $schema = $this->registry->schemaById($frame->schemaId);
        $reader = $this->readers[$frame->schemaId] ??= new AvroIODatumReader($schema, $schema);

        $io = new TruncationDetectingStringIO($frame->avroBytes);

        try {
            $payload = $reader->read(new AvroIOBinaryDecoder($io));
        } catch (\Throwable $error) {
            throw new MalformedMessage(
                "Avro payload does not decode with schema {$frame->schemaId}: {$error->getMessage()}",
                previous: $error,
            );
        }

        if ($io->hadShortRead) {
            throw new MalformedMessage("Avro payload is truncated or corrupt for schema {$frame->schemaId}");
        }

        if (!is_array($payload)) {
            throw new MalformedMessage('Avro payload must decode to a record');
        }

        /** @var array<string, mixed> $payload */
        return new IncomingMessage(
            messageId: $meta['message_id'],
            messageName: $meta['message_name'],
            createdAt: $meta['created_at'],
            payload: $payload,
            headers: $headers,
        );
    }
}
