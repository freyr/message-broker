<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer\Avro;

use Apache\Avro\Schema\AvroSchema;

/**
 * Dependency-free Confluent-compatible registry client over PHP streams
 * (no Guzzle/PSR-7 — the surface is two GET/POST calls; D11 policy).
 *
 * Both lookups are cached for the life of the process: relays and
 * consumers are long-running, so the registry is hit once per
 * subject/id, not per message.
 */
final class HttpSchemaRegistry implements SchemaRegistry
{
    /** @var array<string, int> */
    private array $idBySubject = [];

    /** @var array<int, AvroSchema> */
    private array $schemaById = [];

    /** @param string $baseUrl e.g. http://apicurio:8080/apis/ccompat/v7 */
    public function __construct(
        private readonly string $baseUrl,
        private readonly float $timeoutSec = 3.0,
    ) {}

    public function idFor(string $subject, string $schemaJson): int
    {
        return $this->idBySubject[$subject] ??= $this->lookupId($subject, $schemaJson);
    }

    public function schemaById(int $id): AvroSchema
    {
        return $this->schemaById[$id] ??= $this->fetchSchema($id);
    }

    private function lookupId(string $subject, string $schemaJson): int
    {
        $response = $this->request(
            'POST',
            '/subjects/'.rawurlencode($subject),
            json_encode([
                'schema' => $schemaJson,
            ], JSON_THROW_ON_ERROR),
        );

        $id = $response['id'] ?? null;
        if (!is_int($id)) {
            throw new RegistryUnavailable("Registry response for subject '{$subject}' carries no integer id");
        }

        return $id;
    }

    private function fetchSchema(int $id): AvroSchema
    {
        $response = $this->request('GET', "/schemas/ids/{$id}");

        $schema = $response['schema'] ?? null;
        if (!is_string($schema)) {
            throw new RegistryUnavailable("Registry response for schema id {$id} carries no schema string");
        }

        $parsed = AvroSchema::parse($schema);
        if (!$parsed instanceof AvroSchema) {
            throw new RegistryUnavailable("Registry returned an unparseable schema for id {$id}");
        }

        return $parsed;
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, ?string $body = null): array
    {
        $url = rtrim($this->baseUrl, '/').$path;
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'timeout' => $this->timeoutSec,
                'ignore_errors' => true, // read 4xx/5xx bodies instead of warning
                'header' => implode("\r\n", [
                    'Content-Type: application/vnd.schemaregistry.v1+json',
                    'Accept: application/vnd.schemaregistry.v1+json, application/json',
                ]),
                'content' => $body ?? '',
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new RegistryUnavailable("Schema registry unreachable: {$method} {$url}");
        }

        $status = $this->statusCode($http_response_header);
        if ($status === 404) {
            throw new SchemaNotFound(
                "{$method} {$path} → 404 (schema not registered? CI registration is out-of-band)"
            );
        }
        if ($status < 200 || $status >= 300) {
            throw new RegistryUnavailable("{$method} {$path} → HTTP {$status}: {$raw}");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RegistryUnavailable("Non-JSON registry response from {$method} {$path}");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @param list<string> $responseHeaders */
    private function statusCode(array $responseHeaders): int
    {
        // First line: "HTTP/1.1 200 OK"
        $parts = explode(' ', $responseHeaders[0] ?? '', 3);

        return (int) ($parts[1] ?? 0);
    }
}
