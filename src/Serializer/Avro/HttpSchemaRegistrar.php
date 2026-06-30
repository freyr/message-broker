<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer\Avro;

/**
 * POSTs to /subjects/{subject}/versions and PUT/GETs /config/{subject} via the
 * same streams-based HTTP as the read client (no Guzzle — D11). Reuses the
 * RegistryUnavailable taxonomy. Owns the write + governance side only.
 */
final class HttpSchemaRegistrar implements SchemaRegistrar
{
    /** @param string $baseUrl e.g. http://schema-registry:8081 (Confluent SR root) */
    public function __construct(
        private readonly string $baseUrl,
        private readonly float $timeoutSec = 5.0,
    ) {}

    public function register(string $subject, string $schemaJson, ?CompatibilityLevel $compatibility = null): int
    {
        // Govern the subject BEFORE its first version, so the first schema is checked too.
        if ($compatibility !== null) {
            $this->setCompatibility($subject, $compatibility);
        }

        [$status, $raw] = $this->send(
            'POST',
            '/subjects/'.rawurlencode($subject).'/versions',
            json_encode([
                'schema' => $schemaJson,
            ], JSON_THROW_ON_ERROR),
        );
        if ($status === 409) {
            throw new IncompatibleSchema(
                "Schema for '{$subject}' violates the subject's compatibility policy (HTTP 409): {$raw}",
            );
        }
        if ($status === 422) {
            throw new InvalidSchema("Schema for '{$subject}' is invalid (HTTP 422): {$raw}");
        }
        if ($status < 200 || $status >= 300) {
            throw new RegistryUnavailable("POST /subjects/{$subject}/versions → HTTP {$status}: {$raw}");
        }

        $decoded = json_decode($raw, true);
        $id = is_array($decoded) ? ($decoded['id'] ?? null) : null;
        if (!is_int($id)) {
            throw new RegistryUnavailable("Registry registration response for '{$subject}' carries no integer id");
        }

        return $id;
    }

    public function setCompatibility(string $subject, CompatibilityLevel $level): void
    {
        [$status, $raw] = $this->send(
            'PUT',
            '/config/'.rawurlencode($subject),
            json_encode([
                'compatibility' => $level->value,
            ], JSON_THROW_ON_ERROR),
        );
        if ($status < 200 || $status >= 300) {
            throw new RegistryUnavailable("PUT /config/{$subject} → HTTP {$status}: {$raw}");
        }
    }

    public function compatibilityOf(string $subject): ?CompatibilityLevel
    {
        [$status, $raw] = $this->send('GET', '/config/'.rawurlencode($subject));

        // 404 = no per-subject override → the subject inherits the registry default.
        if ($status === 404) {
            return null;
        }
        if ($status < 200 || $status >= 300) {
            throw new RegistryUnavailable("GET /config/{$subject} → HTTP {$status}: {$raw}");
        }

        $decoded = json_decode($raw, true);
        // Confluent SR returns {"compatibilityLevel": "..."}; tolerate the bare {"compatibility": "..."} too.
        $level = is_array($decoded) ? ($decoded['compatibilityLevel'] ?? $decoded['compatibility'] ?? null) : null;

        return is_string($level) ? CompatibilityLevel::tryFrom($level) : null;
    }

    /**
     * @return array{0: int, 1: string} [status, rawBody]
     */
    private function send(string $method, string $path, ?string $body = null): array
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

        return [$this->statusCode($http_response_header), $raw];
    }

    /** @param list<string> $responseHeaders */
    private function statusCode(array $responseHeaders): int
    {
        $parts = explode(' ', $responseHeaders[0] ?? '', 3);

        return (int) ($parts[1] ?? 0);
    }
}
