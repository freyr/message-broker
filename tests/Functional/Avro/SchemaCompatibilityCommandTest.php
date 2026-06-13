<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Avro;

use Freyr\MessageBroker\Console\SchemaCompatibilityCommand;
use Freyr\MessageBroker\Serializer\Avro\CompatibilityLevel;
use Freyr\MessageBroker\Serializer\Avro\HttpSchemaRegistrar;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * §8/§11: set → read-back compatibility round-trip against the real Confluent
 * Schema Registry.
 *
 * Confluent SR returns 404 from GET /config/{subject} when the subject has no
 * per-subject override (it inherits the registry global default), so
 * compatibilityOf returns null there — exactly the contract the design intends.
 */
final class SchemaCompatibilityCommandTest extends TestCase
{
    use RegistersSchemas;

    private const string SUBJECT = 'order.compatibility_probe';
    private const string SCHEMA_PATH = __DIR__.'/../../Fixtures/schemas/order_placed.avsc';

    public static function tearDownAfterClass(): void
    {
        self::resetCompatibility(self::SUBJECT);
        self::deleteSchema(self::SUBJECT);
    }

    protected function setUp(): void
    {
        // Known baseline every run: subject registered, no per-subject override.
        new HttpSchemaRegistrar(self::registryUrl())
            ->register(self::SUBJECT, (string) file_get_contents(self::SCHEMA_PATH));
        self::resetCompatibility(self::SUBJECT);
    }

    public function testUnoverriddenSubjectIsNullThenSetReadsBack(): void
    {
        $registrar = new HttpSchemaRegistrar(self::registryUrl());

        // No per-subject override → Confluent SR 404s → compatibilityOf is null.
        self::assertNull($registrar->compatibilityOf(self::SUBJECT));

        // `schema:compatibility --subject=… --level=FULL` sets it.
        $set = new CommandTester(new SchemaCompatibilityCommand($registrar));
        $set->execute([
            '--subject' => self::SUBJECT,
            '--level' => 'FULL',
        ]);
        $set->assertCommandIsSuccessful();
        self::assertStringContainsString('FULL (set)', $set->getDisplay());

        // The registrar reads the override back…
        self::assertSame(CompatibilityLevel::Full, $registrar->compatibilityOf(self::SUBJECT));

        // …and the read-mode command (no --level) prints it.
        $read = new CommandTester(new SchemaCompatibilityCommand($registrar));
        $read->execute([
            '--subject' => self::SUBJECT,
        ]);
        $read->assertCommandIsSuccessful();
        self::assertStringContainsString('FULL', $read->getDisplay());
    }

    public function testRejectsUnknownLevel(): void
    {
        $tester = new CommandTester(new SchemaCompatibilityCommand(new HttpSchemaRegistrar(self::registryUrl())));
        $tester->execute([
            '--subject' => self::SUBJECT,
            '--level' => 'NONSENSE',
        ]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('Unknown level', $tester->getDisplay());
    }

    private static function resetCompatibility(string $subject): void
    {
        @file_get_contents(
            self::registryUrl().'/config/'.rawurlencode($subject),
            false,
            stream_context_create([
                'http' => [
                    'method' => 'DELETE',
                    'ignore_errors' => true,
                    'timeout' => 5.0,
                ],
            ]),
        );
    }
}
