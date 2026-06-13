<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Avro;

use Freyr\MessageBroker\Console\SchemaRegisterCommand;
use Freyr\MessageBroker\Serializer\Avro\FileSchemaStore;
use Freyr\MessageBroker\Serializer\Avro\HttpSchemaRegistrar;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Drives the command against the real Confluent Schema Registry. */
final class SchemaRegisterCommandTest extends TestCase
{
    use RegistersSchemas;

    private const string SCHEMA_PATH = __DIR__.'/../../Fixtures/schemas/order_placed.avsc';

    private function command(): SchemaRegisterCommand
    {
        return new SchemaRegisterCommand(
            new FileSchemaStore([
                'order.placed' => self::SCHEMA_PATH,
            ]),
            new HttpSchemaRegistrar(self::registryUrl()),
        );
    }

    public function testDryRunListsWithoutRegistering(): void
    {
        $tester = new CommandTester($this->command());
        $tester->execute([
            '--dry-run' => true,
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('order.placed', $tester->getDisplay());
        self::assertStringContainsString('would register', $tester->getDisplay());
    }

    public function testRegistersMappedSubjectAndIsIdempotent(): void
    {
        $first = new CommandTester($this->command());
        $first->execute([]);
        $first->assertCommandIsSuccessful();
        self::assertMatchesRegularExpression('/order\.placed → \d+/', $first->getDisplay());

        // Re-register: identical schema must return the SAME id.
        $idFirst = $this->extractId($first->getDisplay());
        $second = new CommandTester($this->command());
        $second->execute([]);
        $second->assertCommandIsSuccessful();
        self::assertSame(
            $idFirst,
            $this->extractId($second->getDisplay()),
            'idempotent re-register returns the same id'
        );
    }

    private function extractId(string $display): int
    {
        self::assertSame(1, preg_match('/order\.placed → (\d+)/', $display, $m));

        return (int) $m[1];
    }
}
