<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer\Avro;

use Apache\Avro\Schema\AvroSchema;
use Freyr\MessageBroker\Cache\ArrayCachePool;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Dependency-free Confluent-compatible registry client over PHP streams
 * (no Guzzle/PSR-7 — D11). Lookups cache behind an injected PSR-6 pool so a
 * shared Redis removes cold-start hits across producer/relay/consumer
 * restarts and processes (design §7). Cached values are scalars only:
 * id-by-subject (int) and schema-by-id (schema JSON string). Schemas parse
 * to AvroSchema on read, kept in a per-process parsed map so the parse cost
 * is paid once per id per process. Schema ids are immutable once registered,
 * so cached entries have no TTL (design §13).
 *
 * Only SUCCESSES are cached: a SchemaNotFound leaves no entry, so a later
 * lookup (after out-of-band registration) retries the registry.
 */
final class HttpSchemaRegistry implements SchemaRegistry
{
    private const string ID_PREFIX = 'mb.subject.id.';

    private const string SCHEMA_PREFIX = 'mb.schema.json.';

    private readonly CacheItemPoolInterface $cache;

    /** @var array<int, AvroSchema> per-process parsed schemas */
    private array $parsed = [];

    /** @param string $baseUrl e.g. http://schema-registry:8081 (Confluent SR root) */
    public function __construct(
        private readonly string $baseUrl,
        ?CacheItemPoolInterface $cache = null,
        private readonly float $timeoutSec = 3.0,
    ) {
        $this->cache = $cache ?? new ArrayCachePool();
    }

    public function idFor(string $subject, string $schemaJson): int
    {
        // Cache key is the subject only (not subject+schemaJson). Safe because
        // the producer always passes the deterministic FileSchemaStore schema
        // for a subject — one schema per subject per process; a second schema
        // VERSION under the same subject would resolve to the first's cached id
        // within the process lifetime.
        $item = $this->cache->getItem(self::ID_PREFIX.self::hash($subject));
        if ($item->isHit()) {
            $cached = $item->get();
            if (is_int($cached)) {
                return $cached;
            }
        }

        $id = $this->lookupId($subject, $schemaJson);
        $this->cache->save($item->set($id));

        return $id;
    }

    public function schemaById(int $id): AvroSchema
    {
        if (isset($this->parsed[$id])) {
            return $this->parsed[$id];
        }

        $item = $this->cache->getItem(self::SCHEMA_PREFIX.$id);
        $schemaJson = $item->isHit() && is_string($item->get()) ? (string) $item->get() : null;

        if ($schemaJson === null) {
            $schemaJson = $this->fetchSchemaJson($id);
            $this->cache->save($item->set($schemaJson));
        }

        return $this->parsed[$id] = $this->parse($id, $schemaJson);
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

    private function fetchSchemaJson(int $id): string
    {
        $response = $this->request('GET', "/schemas/ids/{$id}");

        $schema = $response['schema'] ?? null;
        if (!is_string($schema)) {
            throw new RegistryUnavailable("Registry response for schema id {$id} carries no schema string");
        }

        return $schema;
    }

    private function parse(int $id, string $schemaJson): AvroSchema
    {
        $parsed = AvroSchema::parse($schemaJson);
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
                'ignore_errors' => true,
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
                "{$method} {$path} → 404 (schema not registered? CI registration is out-of-band)",
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
        $parts = explode(' ', $responseHeaders[0] ?? '', 3);

        return (int) ($parts[1] ?? 0);
    }

    private static function hash(string $subject): string
    {
        // Subjects (message_name) can contain '.'; never reserved PSR-6 chars,
        // but hashing keeps keys uniform and short regardless of subject.
        return hash('xxh128', $subject);
    }
}
