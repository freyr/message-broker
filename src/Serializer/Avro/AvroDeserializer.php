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
 * headers, payload decoded by resolving the writer schema (fetched by the
 * frame's derived id) against this consumer's reader schema (its current
 * committed schema for the message, by name). Avro fills fields the writer
 * lacks from the reader's defaults — this is the forward-compat mechanism, so
 * a v1-framed message decodes cleanly against a v2 reader. Readers are cached
 * per (message, writer id) for the process lifetime.
 *
 * Failure taxonomy (spec A10): structural problems throw MalformedMessage
 * (→ DLQ, a malformed message never improves); schema-resolution failures
 * (SchemaNotFound, an unmapped reader schema) propagate untouched so the
 * delivery is requeued — never dead-lettered.
 */
final class AvroDeserializer implements Deserializer
{
    /** @var array<string, AvroIODatumReader> */
    private array $readers = [];

    public function __construct(
        private readonly SchemaRegistry $registry,
        private readonly FileSchemaStore $readerSchemas,
    ) {}

    /** @param array<string, mixed> $headers */
    public function deserialize(string $bytes, array $headers = []): IncomingMessage
    {
        $meta = MetadataHeader::parse($headers);

        $frame = ConfluentFrame::parse($bytes);

        // Registry-less reader/writer resolution. Schema-resolution failures
        // (SchemaNotFound from the registry, an unmapped reader schema)
        // intentionally propagate from here (A10) so the delivery requeues.
        $writerSchema = $this->registry->schemaById($frame->schemaId);
        $readerSchema = $this->readerSchemas->schemaFor($meta['message_name']);

        $cacheKey = $meta['message_name'].':'.$frame->schemaId;
        $reader = $this->readers[$cacheKey] ??= new AvroIODatumReader($writerSchema, $readerSchema);

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
