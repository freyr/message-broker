<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Avro;

use Freyr\MessageBroker\Serializer\Avro\CompatibilityLevel;
use Freyr\MessageBroker\Serializer\Avro\HttpSchemaRegistrar;
use Freyr\MessageBroker\Serializer\Avro\IncompatibleSchema;
use Freyr\MessageBroker\Serializer\Avro\InvalidSchema;
use PHPUnit\Framework\TestCase;

/**
 * The registrar must tell a permanent schema rejection (HTTP 409, incompatible
 * with the subject's compatibility policy) apart from a registry outage.
 */
final class SchemaTaxonomyTest extends TestCase
{
    use RegistersSchemas;

    private const string SUBJECT = 'order.taxonomy_probe';

    public static function tearDownAfterClass(): void
    {
        self::resetCompatibility(self::SUBJECT);
        self::deleteSchema(self::SUBJECT);
    }

    public function testIncompatibleSchemaRegistrationThrowsIncompatibleSchema(): void
    {
        $registrar = new HttpSchemaRegistrar(self::registryUrl());

        // v1 under FULL compatibility (both backward and forward enforced).
        $v1 = '{"type":"record","name":"TaxonomyProbe","fields":[{"name":"a","type":"string"}]}';
        $registrar->register(self::SUBJECT, $v1, CompatibilityLevel::Full);

        // Adding a field with no default breaks FULL compatibility → SR 409.
        $v2 = '{"type":"record","name":"TaxonomyProbe","fields":'
            .'[{"name":"a","type":"string"},{"name":"b","type":"string"}]}';

        $this->expectException(IncompatibleSchema::class);
        $registrar->register(self::SUBJECT, $v2);
    }

    public function testInvalidSchemaRegistrationThrowsInvalidSchema(): void
    {
        $registrar = new HttpSchemaRegistrar(self::registryUrl());

        // `fields` must be an array; a string makes Schema Registry answer 422.
        $bad = '{"type":"record","name":"TaxonomyProbe","fields":"not-an-array"}';

        $this->expectException(InvalidSchema::class);
        $registrar->register(self::SUBJECT, $bad);
    }

    private static function resetCompatibility(string $subject): void
    {
        @file_get_contents(
            self::registryUrl().'/config/'.rawurlencode($subject),
            false,
            stream_context_create(['http' => ['method' => 'DELETE', 'ignore_errors' => true, 'timeout' => 5.0]]),
        );
    }
}
