<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures;

use Apache\Avro\Schema\AvroSchema;
use Freyr\MessageBroker\Serializer\Avro\SchemaNotFound;
use Freyr\MessageBroker\Serializer\Avro\SchemaRegistry;

/** In-memory SchemaRegistry for unit tests. */
final class StubSchemaRegistry implements SchemaRegistry
{
    /** @param array<int, AvroSchema> $schemas id → schema */
    public function __construct(
        private readonly array $schemas,
        private readonly ?int $idForAnySubject = null,
    ) {}

    public function idFor(string $subject, string $schemaJson): int
    {
        return $this->idForAnySubject ?? throw new SchemaNotFound("No id configured for subject '{$subject}'");
    }

    public function schemaById(int $id): AvroSchema
    {
        return $this->schemas[$id] ?? throw new SchemaNotFound("No schema {$id} configured");
    }
}
