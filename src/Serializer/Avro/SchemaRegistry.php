<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer\Avro;

use Apache\Avro\Schema\AvroSchema;

/**
 * Read seam for resolving Avro schemas: the library derives an id for a
 * committed schema and fetches the writer schema for a frame id. Served by
 * FileSchemaRegistry over the committed .avsc set — registry-free, no network,
 * no registry-assigned ids.
 */
interface SchemaRegistry
{
    /**
     * Resolve the derived id of this exact committed schema under the subject.
     *
     * Throws SchemaNotFound when the schema is not in the committed set.
     */
    public function idFor(string $subject, string $schemaJson): int;

    /**
     * Fetch the writer schema for a frame's derived schema id.
     *
     * Throws SchemaNotFound when the id is not in the committed set.
     */
    public function schemaById(int $id): AvroSchema;
}
