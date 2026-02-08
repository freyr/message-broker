<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Amqp;

use Freyr\MessageBroker\Amqp\TopologyManager;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TopologyManager.
 *
 * Tests dependency resolution, dry-run output, and edge cases
 * without requiring a live RabbitMQ connection.
 */
final class TopologyManagerTest extends TestCase
{
    public function testDryRunWithIndependentExchanges(): void
    {
        $topology = $this->createTopology(
            exchanges: [
                'alpha' => [
                    'type' => 'topic',
                    'durable' => true,
                    'arguments' => [],
                ],
                'beta' => [
                    'type' => 'direct',
                    'durable' => true,
                    'arguments' => [],
                ],
            ],
        );

        $manager = new TopologyManager($topology);
        $actions = $manager->dryRun();

        // Both exchanges should be declared
        $this->assertCount(2, $actions);
        $combined = implode("\n", $actions);
        $this->assertStringContainsString('"alpha"', $combined);
        $this->assertStringContainsString('"beta"', $combined);
    }

    public function testDryRunRespectsAlternateExchangeDependencyOrder(): void
    {
        $topology = $this->createTopology(
            exchanges: [
                'commerce' => [
                    'type' => 'topic',
                    'durable' => true,
                    'arguments' => [
                        'alternate-exchange' => 'unrouted',
                    ],
                ],
                'unrouted' => [
                    'type' => 'fanout',
                    'durable' => true,
                    'arguments' => [],
                ],
            ],
        );

        $manager = new TopologyManager($topology);
        $actions = $manager->dryRun();

        // "unrouted" must be declared before "commerce"
        $this->assertCount(2, $actions);
        $unroutedIndex = $this->findActionIndex($actions, '"unrouted"');
        $commerceIndex = $this->findActionIndex($actions, '"commerce"');
        $this->assertLessThan($commerceIndex, $unroutedIndex);
    }

    public function testDryRunRespectsChainedDependencyOrder(): void
    {
        $topology = $this->createTopology(
            exchanges: [
                'a' => [
                    'type' => 'topic',
                    'durable' => true,
                    'arguments' => [
                        'alternate-exchange' => 'b',
                    ],
                ],
                'b' => [
                    'type' => 'fanout',
                    'durable' => true,
                    'arguments' => [
                        'alternate-exchange' => 'c',
                    ],
                ],
                'c' => [
                    'type' => 'fanout',
                    'durable' => true,
                    'arguments' => [],
                ],
            ],
        );

        $manager = new TopologyManager($topology);
        $actions = $manager->dryRun();

        // c → b → a
        $this->assertCount(3, $actions);
        $cIndex = $this->findActionIndex($actions, '"c"');
        $bIndex = $this->findActionIndex($actions, '"b"');
        $aIndex = $this->findActionIndex($actions, '"a"');
        $this->assertLessThan($bIndex, $cIndex);
        $this->assertLessThan($aIndex, $bIndex);
    }

    public function testDryRunDetectsCycleInDependencies(): void
    {
        $topology = $this->createTopology(
            exchanges: [
                'a' => [
                    'type' => 'topic',
                    'durable' => true,
                    'arguments' => [
                        'alternate-exchange' => 'b',
                    ],
                ],
                'b' => [
                    'type' => 'fanout',
                    'durable' => true,
                    'arguments' => [
                        'alternate-exchange' => 'a',
                    ],
                ],
            ],
        );

        $manager = new TopologyManager($topology);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cycle detected');
        $manager->dryRun();
    }

    public function testDryRunIgnoresExternalExchangeReferences(): void
    {
        // alternate-exchange references an exchange not in the topology — ignored
        $topology = $this->createTopology(
            exchanges: [
                'commerce' => [
                    'type' => 'topic',
                    'durable' => true,
                    'arguments' => [
                        'alternate-exchange' => 'external',
                    ],
                ],
            ],
        );

        $manager = new TopologyManager($topology);
        $actions = $manager->dryRun();

        $this->assertCount(1, $actions);
        $this->assertStringContainsString('"commerce"', $actions[0]);
    }

    public function testDryRunWithFullTopology(): void
    {
        $topology = $this->createTopology(
            exchanges: [
                'commerce' => [
                    'type' => 'topic',
                    'durable' => true,
                    'arguments' => [],
                ],
                'dlx' => [
                    'type' => 'direct',
                    'durable' => true,
                    'arguments' => [],
                ],
            ],
            queues: [
                'orders_queue' => [
                    'durable' => true,
                    'arguments' => [
                        'x-dead-letter-exchange' => 'dlx',
                    ],
                ],
            ],
            bindings: [
                [
                    'exchange' => 'commerce',
                    'queue' => 'orders_queue',
                    'binding_key' => 'order.*',
                    'arguments' => [],
                ],
            ],
        );

        $manager = new TopologyManager($topology);
        $actions = $manager->dryRun();

        $this->assertCount(4, $actions); // 2 exchanges + 1 queue + 1 binding

        // Check exchange declarations present
        $this->assertStringContainsString('exchange "commerce"', $actions[0]);
        $this->assertStringContainsString('topic', $actions[0]);

        // Check queue declaration
        $queueAction = $actions[2];
        $this->assertStringContainsString('queue "orders_queue"', $queueAction);

        // Check binding
        $bindingAction = $actions[3];
        $this->assertStringContainsString('Bind queue "orders_queue"', $bindingAction);
        $this->assertStringContainsString('exchange "commerce"', $bindingAction);
        $this->assertStringContainsString('"order.*"', $bindingAction);
    }

    public function testDryRunWithEmptyTopology(): void
    {
        $topology = $this->createTopology();
        $manager = new TopologyManager($topology);

        $this->assertSame([], $manager->dryRun());
    }

    public function testDryRunShowsEmptyBindingKey(): void
    {
        $topology = $this->createTopology(
            exchanges: [
                'events' => [
                    'type' => 'fanout',
                    'durable' => true,
                    'arguments' => [],
                ],
            ],
            queues: [
                'all_events' => [
                    'durable' => true,
                    'arguments' => [],
                ],
            ],
            bindings: [
                [
                    'exchange' => 'events',
                    'queue' => 'all_events',
                    'binding_key' => '',
                    'arguments' => [],
                ],
            ],
        );

        $manager = new TopologyManager($topology);
        $actions = $manager->dryRun();

        $bindingAction = $actions[2]; // after exchange + queue
        $this->assertStringContainsString('(empty)', $bindingAction);
    }

    /**
     * Find the index of the first action containing the given substring.
     *
     * @param array<int, string> $actions
     */
    private function findActionIndex(array $actions, string $needle): int
    {
        foreach ($actions as $index => $action) {
            if (str_contains($action, $needle)) {
                return $index;
            }
        }

        $this->fail(sprintf('No action found containing "%s"', $needle));
    }

    /**
     * @param array<string, array{type: string, durable: bool, arguments: array<string, mixed>}> $exchanges
     * @param array<string, array{durable: bool, arguments: array<string, mixed>}> $queues
     * @param array<int, array{exchange: string, queue: string, binding_key: string, arguments: array<string, mixed>}> $bindings
     *
     * @return array{exchanges: array<string, array{type: string, durable: bool, arguments: array<string, mixed>}>, queues: array<string, array{durable: bool, arguments: array<string, mixed>}>, bindings: array<int, array{exchange: string, queue: string, binding_key: string, arguments: array<string, mixed>}>}
     */
    private function createTopology(array $exchanges = [], array $queues = [], array $bindings = []): array
    {
        return [
            'exchanges' => $exchanges,
            'queues' => $queues,
            'bindings' => $bindings,
        ];
    }
}
