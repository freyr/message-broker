<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer\Avro;

use Apache\Avro\Schema\AvroSchema;
use Apache\Avro\Schema\AvroSchemaParseException;
use Closure;

/**
 * Registry-free SchemaRegistry backed by the committed .avsc set (spec: the
 * registry-less Avro slice). It replaces the HTTP Confluent registry: no
 * network, no registry-assigned ids. Ids are derived from the schema bytes (DerivedSchemaId)
 * and the writer schema for any id is resolved from the committed files.
 *
 * It is constructed over EVERY committed schema version (not just the current
 * one), so a consumer can still decode a frame written by an older,
 * still-deployed producer during a rolling deploy — the versioned committed
 * layout (schemas/<subject>/v<N>.avsc, immutable per version) is what keeps
 * those historical ids resolvable.
 *
 * A derived-id collision (two DIFFERENT committed schemas hashing to the same
 * id) throws at construction, so a collision is a fail-fast startup error and
 * never a silent runtime mis-resolution.
 */
final class FileSchemaRegistry implements SchemaRegistry
{
    /** @var array<int, AvroSchema> derived id → writer schema */
    private readonly array $byId;

    /** @var Closure(string): int */
    private readonly Closure $deriveId;

    /**
     * @param list<string>                 $schemaPaths every committed schema version's .avsc path
     * @param (callable(string): int)|null $deriveId    schema-id deriver; defaults to DerivedSchemaId
     */
    public function __construct(array $schemaPaths, ?callable $deriveId = null)
    {
        $this->deriveId = $deriveId !== null
            ? Closure::fromCallable($deriveId)
            : DerivedSchemaId::fromSchemaJson(...);

        $byId = [];
        $jsonById = [];

        foreach ($schemaPaths as $path) {
            $json = @file_get_contents($path);
            if ($json === false) {
                throw new \RuntimeException("Cannot read Avro schema file '{$path}'");
            }

            $id = ($this->deriveId)($json);

            if (isset($jsonById[$id]) && $jsonById[$id] !== $json) {
                throw new \RuntimeException(sprintf(
                    "Derived schema id collision (%d): '%s' hashes to the same id as another committed schema",
                    $id,
                    $path,
                ));
            }

            $byId[$id] = $this->parse($json, $path);
            $jsonById[$id] = $json;
        }

        $this->byId = $byId;
    }

    public function idFor(string $subject, string $schemaJson): int
    {
        $id = ($this->deriveId)($schemaJson);

        if (!isset($this->byId[$id])) {
            throw new SchemaNotFound(sprintf("No committed schema for subject '%s' (derived id %d)", $subject, $id));
        }

        return $id;
    }

    public function schemaById(int $id): AvroSchema
    {
        return $this->byId[$id] ?? throw new SchemaNotFound("No committed schema for id {$id}");
    }

    private function parse(string $json, string $path): AvroSchema
    {
        try {
            $schema = AvroSchema::parse($json);
        } catch (AvroSchemaParseException $error) {
            throw new \RuntimeException(
                "Avro schema file '{$path}' is not valid: {$error->getMessage()}",
                previous: $error,
            );
        }

        if (!$schema instanceof AvroSchema) {
            throw new \RuntimeException("Avro schema file '{$path}' did not parse to an AvroSchema");
        }

        return $schema;
    }
}
