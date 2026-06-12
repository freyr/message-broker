<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer\Avro;

use Apache\Avro\Schema\AvroSchema;

/**
 * Confluent-compatible schema registry, read-only by design (spec A1):
 * the library looks up ids and fetches schemas; registration is an
 * out-of-band CI concern and deliberately has no method here.
 */
interface SchemaRegistry
{
    /**
     * Resolve the registered id of this exact schema under the subject
     * (POST /subjects/{subject} lookup — never registers).
     */
    public function idFor(string $subject, string $schemaJson): int;

    /**
     * Fetch the writer schema for a Confluent frame id
     * (GET /schemas/ids/{id}).
     */
    public function schemaById(int $id): AvroSchema;
}
