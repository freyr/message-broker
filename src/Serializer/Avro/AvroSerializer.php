<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer\Avro;

use Apache\Avro\Datum\AvroIOBinaryEncoder;
use Apache\Avro\Datum\AvroIODatumWriter;
use Apache\Avro\IO\AvroStringIO;
use Freyr\MessageBroker\Serializer\Serializer;
use Freyr\MessageBroker\Serializer\WireMessage;

/**
 * Relay-side Avro encoding (spec A2/A4): the body carries the Confluent-framed
 * payload record only; the envelope travels as x-* transport headers
 * (which therefore shadow any produce-time headers of the same name —
 * the x-* namespace belongs to the library).
 *
 * The schema comes from the committed local files; the registry is consulted
 * once per message_name (cached) for the frame's schema id. Registry failures
 * here are transient by definition — the relay backs off the lane head and
 * retries forever (D17); conformance failures should have been rejected at
 * produce time by AvroWireValidator — if it is not wired on the lane, a
 * non-conforming row blocks the lane until manually removed.
 */
final class AvroSerializer implements Serializer
{
    public const string CONTENT_TYPE = 'avro/binary';

    /** @var array<string, AvroIODatumWriter> */
    private array $writers = [];

    public function __construct(
        private readonly FileSchemaStore $schemas,
        private readonly SchemaRegistry $registry,
    ) {}

    public function serialize(array $wire): WireMessage
    {
        $metadata = $wire['metadata'] ?? null;
        $payload = $wire['payload'] ?? null;
        if (!is_array($metadata) || !is_array($payload)) {
            throw new \InvalidArgumentException('Wire document must contain metadata and payload sections');
        }

        $messageName = $metadata['message_name'] ?? null;
        $messageId = $metadata['message_id'] ?? null;
        $createdAt = $metadata['created_at'] ?? null;
        if (!is_string($messageName) || !is_string($messageId) || !is_int($createdAt)) {
            throw new \InvalidArgumentException(
                'Wire metadata must contain message_name (string), message_id (string) and created_at (epoch ms int)',
            );
        }

        $schemaId = $this->registry->idFor($messageName, $this->schemas->schemaJsonFor($messageName));

        $writer = $this->writers[$messageName]
            ??= new AvroIODatumWriter($this->schemas->schemaFor($messageName));
        $io = new AvroStringIO();
        $writer->write($payload, new AvroIOBinaryEncoder($io));

        $avroBytes = $io->string();
        if (!is_string($avroBytes)) {
            throw new \RuntimeException('AvroStringIO::string() did not return a string');
        }

        return new WireMessage(
            bytes: new ConfluentFrame($schemaId, $avroBytes)
                ->bytes(),
            contentType: self::CONTENT_TYPE,
            headers: [
                'x-message-id' => $messageId,
                'x-message-name' => $messageName,
                'x-created-at' => $createdAt,
            ],
        );
    }
}
