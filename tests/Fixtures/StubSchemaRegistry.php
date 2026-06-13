<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures;

use Apache\Avro\Schema\AvroSchema;
use Freyr\MessageBroker\Serializer\Avro\RegistryUnavailable;
use Freyr\MessageBroker\Serializer\Avro\SchemaRegistry;

/** In-memory SchemaRegistry for unit tests; optionally simulates an outage. */
final class StubSchemaRegistry implements SchemaRegistry
{
    /** @param array<int, AvroSchema> $schemas id → schema */
    public function __construct(
        private readonly array $schemas,
        private readonly ?int $idForAnySubject = null,
        private readonly bool $unavailable = false,
    ) {}

    public function idFor(string $subject, string $schemaJson): int
    {
        $this->failIfUnavailable();

        return $this->idForAnySubject ?? throw new \LogicException('No id configured');
    }

    public function schemaById(int $id): AvroSchema
    {
        $this->failIfUnavailable();

        return $this->schemas[$id] ?? throw new \LogicException("No schema {$id} configured");
    }

    private function failIfUnavailable(): void
    {
        if ($this->unavailable) {
            throw new RegistryUnavailable('stubbed outage');
        }
    }
}
