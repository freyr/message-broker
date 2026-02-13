<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Command;

use Freyr\MessageBroker\Amqp\Command\SetupAmqpTopologyCommand;
use Freyr\MessageBroker\Tests\Functional\FunctionalTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Functional tests for SetupAmqpTopologyCommand.
 *
 * Tests the command against a live RabbitMQ instance (Docker).
 * Verifies: declaration, idempotency, dry-run, and dump modes.
 */
final class SetupAmqpTopologyCommandTest extends FunctionalTestCase
{
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var SetupAmqpTopologyCommand $command */
        $command = $this->getContainer()
            ->get(SetupAmqpTopologyCommand::class);
        $this->commandTester = new CommandTester($command);
    }

    public function testDeclareTopologyAgainstRabbitMq(): void
    {
        // Given: clean RabbitMQ (test topology exchanges/queues may not exist)
        $this->deleteTestTopology();

        // When: run the command with DSN
        $dsn = $this->getAmqpDsn();
        $exitCode = $this->commandTester->execute([
            '--dsn' => $dsn,
        ]);

        // Then: command succeeds
        $output = $this->commandTester->getDisplay();
        $this->assertSame(Command::SUCCESS, $exitCode, 'Command should succeed. Output: '.$output);
        $this->assertStringContainsString('[OK]', $output);
        $this->assertStringContainsString('topology_test_exchange', $output);
        $this->assertStringContainsString('topology_test_queue', $output);

        // Verify: exchange and queue exist in RabbitMQ
        $this->assertExchangeExists('topology_test_exchange');
        $this->assertQueueExistsInRabbitMq('topology_test_queue');
    }

    public function testIdempotentExecution(): void
    {
        // Given: topology already declared
        $dsn = $this->getAmqpDsn();
        $this->commandTester->execute([
            '--dsn' => $dsn,
        ]);

        // When: run again
        $exitCode = $this->commandTester->execute([
            '--dsn' => $dsn,
        ]);

        // Then: still succeeds (idempotent)
        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('[OK]', $output);
    }

    public function testDryRunDoesNotConnect(): void
    {
        // When: run with --dry-run
        $exitCode = $this->commandTester->execute([
            '--dry-run' => true,
        ]);

        // Then: shows planned actions, succeeds
        $output = $this->commandTester->getDisplay();
        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Dry Run', $output);
        $this->assertStringContainsString('Declare exchange', $output);
        $this->assertStringContainsString('Declare queue', $output);
        $this->assertStringContainsString('Bind queue', $output);
    }

    public function testDumpOutputsValidJson(): void
    {
        // When: run with --dump
        $exitCode = $this->commandTester->execute([
            '--dump' => true,
        ]);

        // Then: output is valid JSON with correct structure
        $output = $this->commandTester->getDisplay();
        $this->assertSame(Command::SUCCESS, $exitCode);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);

        /** @var array{exchanges: list<array<string, mixed>>, queues: list<array<string, mixed>>, bindings: list<array<string, mixed>>} $decoded */
        $this->assertArrayHasKey('exchanges', $decoded);
        $this->assertArrayHasKey('queues', $decoded);
        $this->assertArrayHasKey('bindings', $decoded);

        // Verify binding uses routing_key (not binding_key)
        $this->assertNotEmpty($decoded['bindings']);
        $this->assertArrayHasKey('routing_key', $decoded['bindings'][0]);
        $this->assertArrayNotHasKey('binding_key', $decoded['bindings'][0]);
    }

    public function testDumpToFile(): void
    {
        $outputFile = sys_get_temp_dir().'/rabbitmq-definitions-test.json';

        // Clean up from previous runs
        if (file_exists($outputFile)) {
            unlink($outputFile);
        }

        // When: run with --dump --output
        $exitCode = $this->commandTester->execute([
            '--dump' => true,
            '--output' => $outputFile,
        ]);

        // Then: file contains valid JSON
        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertFileExists($outputFile);

        $content = file_get_contents($outputFile);
        $this->assertIsString($content);
        $decoded = json_decode($content, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('exchanges', $decoded);

        // Clean up
        unlink($outputFile);
    }

    public function testDumpWithCustomVhost(): void
    {
        // When: run with --dump --vhost
        $exitCode = $this->commandTester->execute([
            '--dump' => true,
            '--vhost' => 'my-vhost',
        ]);

        // Then: vhost applied to all entries
        $output = $this->commandTester->getDisplay();
        $this->assertSame(Command::SUCCESS, $exitCode);

        /** @var array{exchanges: list<array<string, mixed>>, queues: list<array<string, mixed>>, bindings: list<array<string, mixed>>} $decoded */
        $decoded = json_decode($output, true);
        $this->assertSame('my-vhost', $decoded['exchanges'][0]['vhost']);
        $this->assertSame('my-vhost', $decoded['queues'][0]['vhost']);
        $this->assertSame('my-vhost', $decoded['bindings'][0]['vhost']);
    }

    public function testFailsWithInvalidDsn(): void
    {
        // When: run with invalid DSN
        $exitCode = $this->commandTester->execute([
            '--dsn' => 'amqp://guest:guest@invalid-host-that-does-not-exist:5672/%2f',
        ]);

        // Then: command fails with clear error
        $this->assertSame(Command::FAILURE, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Failed to connect', $output);
    }

    private function getAmqpDsn(): string
    {
        /** @var string */
        return $_ENV['MESSENGER_AMQP_DSN'] ?? 'amqp://guest:guest@127.0.0.1:5673/%2f';
    }

    /**
     * Delete test topology exchanges/queues to ensure clean state.
     */
    private function deleteTestTopology(): void
    {
        try {
            $channel = self::getAmqpConnection()->channel();

            // Delete queues first (bindings removed automatically)
            try {
                $channel->queue_delete('topology_test_queue');
            } catch (\Exception) {
                // Queue may not exist
            }

            // Delete exchanges
            try {
                $channel->exchange_delete('topology_test_exchange');
            } catch (\Exception) {
                // Exchange may not exist
            }

            $channel->close();
        } catch (\Exception) {
            // RabbitMQ may not be reachable — test will fail later
        }
    }

    /**
     * Assert an exchange exists in RabbitMQ using passive declare.
     */
    private function assertExchangeExists(string $name): void
    {
        $channel = self::getAmqpConnection()->channel();

        try {
            // Passive declare: succeeds if exchange exists, throws if not
            $channel->exchange_declare($name, 'topic', true);
        } catch (\Exception $e) {
            $this->fail(sprintf('Exchange "%s" does not exist in RabbitMQ: %s', $name, $e->getMessage()));
        } finally {
            $channel->close();
        }
    }

    /**
     * Assert a queue exists in RabbitMQ using passive declare.
     */
    private function assertQueueExistsInRabbitMq(string $name): void
    {
        $channel = self::getAmqpConnection()->channel();

        try {
            // Passive declare: succeeds if queue exists, throws if not
            $channel->queue_declare($name, true);
        } catch (\Exception $e) {
            $this->fail(sprintf('Queue "%s" does not exist in RabbitMQ: %s', $name, $e->getMessage()));
        } finally {
            $channel->close();
        }
    }
}
