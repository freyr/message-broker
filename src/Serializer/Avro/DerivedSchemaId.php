<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer\Avro;

/**
 * Registry-free schema id: derived from the committed schema bytes instead of
 * assigned by a Confluent Schema Registry. It is a crc32 of the raw .avsc
 * content masked to the Confluent frame's signed-positive 31-bit range
 * (0 .. 0x7FFFFFFF), so it drops straight into the existing wire frame.
 *
 * The committed file is the canonical source — deliberately NO Avro Parsing
 * Canonical Form (absent from apache/avro-PHP): two byte-identical files derive
 * the same id, a reformat derives a new id (harmless, the old version stays in
 * the committed set). Uniqueness across the committed set is guarded at
 * FileSchemaRegistry construction and in CI (schema:validate).
 */
final class DerivedSchemaId
{
    public static function fromSchemaJson(string $schemaJson): int
    {
        return crc32($schemaJson) & 0x7FFFFFFF;
    }
}
