<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer\Avro;

use Apache\Avro\Datum\AvroIOBinaryDecoder;
use Apache\Avro\Datum\AvroIODatumReader;
use Apache\Avro\IO\AvroStringIO;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Serializer\Deserializer;
use Freyr\MessageBroker\Serializer\MalformedMessage;

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
        $messageId = $headers['x-message-id'] ?? null;
        $messageName = $headers['x-message-name'] ?? null;
        $createdAt = $headers['x-created-at'] ?? null;
        if (!is_string($messageId) || !is_string($messageName) || !is_int($createdAt)) {
            throw new MalformedMessage(
                'Avro delivery requires x-message-id (string), x-message-name (string) and x-created-at (epoch ms int) headers',
            );
        }

        $frame = ConfluentFrame::parse($bytes);

        // Transient registry failures intentionally propagate from here (A10).
        $schema = $this->registry->schemaById($frame->schemaId);
        $reader = $this->readers[$frame->schemaId] ??= new AvroIODatumReader($schema, $schema);

        $io = new class($frame->avroBytes) extends AvroStringIO {
            public bool $hadShortRead = false;

            public function read(mixed $len): string
            {
                $result = parent::read($len);
                if (is_int($len) && $len > 0 && \strlen($result) < $len) {
                    $this->hadShortRead = true;
                }

                return $result;
            }
        };

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
            messageId: $messageId,
            messageName: $messageName,
            createdAt: $createdAt,
            payload: $payload,
            headers: $headers,
        );
    }
}
