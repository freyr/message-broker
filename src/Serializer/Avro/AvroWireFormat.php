<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer\Avro;

use Apache\Avro\Datum\AvroIOBinaryEncoder;
use Apache\Avro\Datum\AvroIODatumWriter;
use Apache\Avro\IO\AvroStringIO;
use Freyr\MessageBroker\Serializer\WireFormat;
use RuntimeException;

/**
 * Produce-side Avro encoder (E2): encodes the payload record, frames it
 * Confluent-style (magic 0x00 + 4-byte big-endian schema id), and returns
 * the bytes for the outbox `body` (LONGBLOB). The metadata envelope is
 * written to the `metadata` column by the producer — not here.
 *
 * Encode order matters: the payload is encoded FIRST (this is the E5/D17
 * poison check — AvroIOTypeException on a non-conforming payload, inside the
 * app transaction), THEN the registry id is fetched (cold path only; cached
 * behind PSR-6). So a bad payload is rejected even when the registry is down.
 */
final class AvroWireFormat implements WireFormat
{
    public const string CONTENT_TYPE = 'avro/binary';

    /** @var array<string, AvroIODatumWriter> */
    private array $writers = [];

    public function __construct(
        private readonly FileSchemaStore $schemas,
        private readonly SchemaRegistry $registry,
    ) {}

    public function contentType(): string
    {
        return self::CONTENT_TYPE;
    }

    /** @param array<string, mixed> $payload */
    public function encode(string $messageName, array $payload): string
    {
        $writer = $this->writers[$messageName]
            ??= new AvroIODatumWriter($this->schemas->schemaFor($messageName));

        $io = new AvroStringIO();
        $writer->write($payload, new AvroIOBinaryEncoder($io)); // throws AvroIOTypeException on bad payload

        $avroBytes = $io->string();
        if (!is_string($avroBytes)) {
            throw new RuntimeException('AvroStringIO::string() did not return a string');
        }

        // Cold path only: cached per message_name behind the registry's PSR-6 pool.
        $schemaId = $this->registry->idFor($messageName, $this->schemas->schemaJsonFor($messageName));

        return new ConfluentFrame($schemaId, $avroBytes)
            ->bytes();
    }
}
