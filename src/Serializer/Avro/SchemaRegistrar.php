<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer\Avro;

/**
 * Write side of the registry, deliberately separate from the read-only
 * SchemaRegistry (no false symmetry — mirrors the Serializer/Deserializer
 * split). Registration stays out-of-band (A1): nothing on the produce or
 * consume path depends on this; CI and deploy scripts do. The write side also
 * owns per-subject compatibility governance (§8) — never the read path.
 */
interface SchemaRegistrar
{
    /**
     * Register the schema under the subject and return the assigned id.
     * Idempotent: re-registering an identical schema returns the existing id.
     * When $compatibility is given, the subject's level is set FIRST (before the
     * first version), so the first schema is governed too.
     *
     * Throws RegistryUnavailable on network failure or unexpected response.
     */
    public function register(string $subject, string $schemaJson, ?CompatibilityLevel $compatibility = null): int;

    /**
     * Set a subject's compatibility level independently of registration
     * (governance action). Idempotent: setting the same level is a no-op.
     */
    public function setCompatibility(string $subject, CompatibilityLevel $level): void;

    /**
     * The subject's per-subject compatibility level, or null when the subject
     * inherits the registry global default (no per-subject override).
     */
    public function compatibilityOf(string $subject): ?CompatibilityLevel;
}
