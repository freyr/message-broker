<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer\Avro;

use Freyr\MessageBroker\Serializer\MalformedMessage;

/**
 * Confluent wire format: 1 magic byte (0x00) + 4-byte big-endian schema id
 * + Avro binary of the payload record. Transport-agnostic — the framed
 * bytes ride AMQP today, Kafka in a later slice.
 */
final readonly class ConfluentFrame
{
    private const string MAGIC = "\x00";

    private const int HEADER_LENGTH = 5;

    public function __construct(
        public int $schemaId,
        public string $avroBytes,
    ) {
        if ($schemaId < 0 || $schemaId > 0x7FFFFFFF) {
            throw new \InvalidArgumentException(sprintf('Confluent schema id out of range: %d', $schemaId));
        }
    }

    public function bytes(): string
    {
        return self::MAGIC.pack('N', $this->schemaId).$this->avroBytes;
    }

    public static function parse(string $framed): self
    {
        if (strlen($framed) < self::HEADER_LENGTH) {
            throw new MalformedMessage('Confluent frame shorter than the 5-byte header');
        }

        if ($framed[0] !== self::MAGIC) {
            throw new MalformedMessage(sprintf('Unknown Confluent magic byte 0x%02X', ord($framed[0])));
        }

        $unpacked = unpack('N', substr($framed, 1, 4));
        if ($unpacked === false || !is_int($unpacked[1])) {
            throw new MalformedMessage('Unreadable Confluent schema id');
        }

        return new self($unpacked[1], substr($framed, self::HEADER_LENGTH));
    }
}
