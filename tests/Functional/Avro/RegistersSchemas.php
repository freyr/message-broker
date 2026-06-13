<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Avro;

use RuntimeException;

/**
 * Out-of-band schema registration — in production this is a CI step
 * (spec A1); the library itself never registers. Tests play the CI role.
 */
trait RegistersSchemas
{
    private static function registryUrl(): string
    {
        $url = getenv('AVRO_REGISTRY_URL') ?: throw new RuntimeException('AVRO_REGISTRY_URL not set');

        return rtrim($url, '/');
    }

    private static function registerSchema(string $subject, string $schemaJson): void
    {
        $url = self::registryUrl().'/subjects/'.rawurlencode($subject).'/versions';
        $response = file_get_contents($url, false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/vnd.schemaregistry.v1+json',
                'content' => json_encode([
                    'schema' => $schemaJson,
                ], JSON_THROW_ON_ERROR),
                'ignore_errors' => true,
                'timeout' => 5.0,
            ],
        ]));

        if ($response === false) {
            throw new RuntimeException("Cannot register schema for '{$subject}' at {$url}");
        }
    }

    /**
     * Deletes all versions of a subject from the registry (soft delete).
     * Used in test teardown to keep the registry clean between runs.
     */
    private static function deleteSchema(string $subject): void
    {
        $url = self::registryUrl().'/subjects/'.rawurlencode($subject);
        file_get_contents($url, false, stream_context_create([
            'http' => [
                'method' => 'DELETE',
                'header' => 'Content-Type: application/vnd.schemaregistry.v1+json',
                'ignore_errors' => true,
                'timeout' => 5.0,
            ],
        ]));
        // Ignore errors: if the subject does not exist the call is a no-op.
    }
}
