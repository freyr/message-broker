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
     *
     * Throws SchemaNotFound when the subject or this exact schema is not
     * registered (404), and RegistryUnavailable on network failures or
     * unexpected responses.
     */
    public function idFor(string $subject, string $schemaJson): int;

    /**
     * Fetch the writer schema for a Confluent frame id
     * (GET /schemas/ids/{id}).
     *
     * Throws SchemaNotFound when the id is unknown to the registry, and
     * RegistryUnavailable on network failures or unexpected responses.
     */
    public function schemaById(int $id): AvroSchema;
}
