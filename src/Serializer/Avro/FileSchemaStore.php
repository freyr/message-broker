<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer\Avro;

use Apache\Avro\Schema\AvroSchema;
use Apache\Avro\Schema\AvroSchemaParseException;

/**
 * The committed-schema source of truth (spec A1): hand-written payload-record
 * .avsc files shipped in the application repo, mapped explicitly per
 * message_name (the same explicit subject→path map style used across the library, D9).
 *
 * Registration with the schema registry is out-of-band (CI) — this store
 * only reads local files; it never talks to the network.
 */
final class FileSchemaStore
{
    /** @var array<string, string> */
    private array $raw = [];

    /** @var array<string, AvroSchema> */
    private array $parsed = [];

    /** @param array<string, string> $paths message_name → path to the .avsc file */
    public function __construct(
        private readonly array $paths,
    ) {}

    /**
     * The mapped subjects (= message_names). The schema:register command
     * drives off exactly this list, so CI registers precisely the subjects
     * the producer will look up.
     *
     * @return list<string>
     */
    public function subjects(): array
    {
        return array_keys($this->paths);
    }

    public function schemaJsonFor(string $messageName): string
    {
        if (isset($this->raw[$messageName])) {
            return $this->raw[$messageName];
        }

        $path = $this->paths[$messageName]
            ?? throw new \InvalidArgumentException("No Avro schema mapped for message '{$messageName}'");

        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read Avro schema file '{$path}' for message '{$messageName}'");
        }

        return $this->raw[$messageName] = $contents;
    }

    public function schemaFor(string $messageName): AvroSchema
    {
        if (isset($this->parsed[$messageName])) {
            return $this->parsed[$messageName];
        }

        try {
            $schema = AvroSchema::parse($this->schemaJsonFor($messageName));
        } catch (AvroSchemaParseException $error) {
            throw new \RuntimeException(
                "Avro schema for message '{$messageName}' is not valid: {$error->getMessage()}",
                previous: $error,
            );
        }

        if (!$schema instanceof AvroSchema) {
            throw new \RuntimeException("Avro schema for message '{$messageName}' did not parse to an AvroSchema");
        }

        return $this->parsed[$messageName] = $schema;
    }
}
