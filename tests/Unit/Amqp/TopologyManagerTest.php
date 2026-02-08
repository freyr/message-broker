<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Amqp;

use Freyr\MessageBroker\Amqp\TopologyManager;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TopologyManager.
 *
 * Tests dependency resolution, dry-run output, argument normalisation,
 * and edge cases without requiring a live RabbitMQ connection.
 */
final class TopologyManagerTest extends TestCase
{
    public function testResolveExchangeOrderWithNoDependencies(): void
    {
        $topology = $this->createTopology(
            exchanges: [
                'alpha' => ['type' => 'topic', 'durable' => true, 'arguments' => []],
                'beta' => ['type' => 'direct', 'durable' => true, 'arguments' => []],
            ],
        );

        $manager = new TopologyManager($topology);
        $order = $manager->resolveExchangeOrder();

        // Both should be present (order is deterministic but not guaranteed alphabetical)
        $this->assertCount(2, $order);
        $this->assertContains('alpha', $order);
        $this->assertContains('beta', $order);
    }

    public function testResolveExchangeOrderWithAlternateExchangeDependency(): void
    {
        $topology = $this->createTopology(
            exchanges: [
                'commerce' => ['type' => 'topic', 'durable' => true, 'arguments' => ['alternate-exchange' => 'unrouted']],
                'unrouted' => ['type' => 'fanout', 'durable' => true, 'arguments' => []],
            ],
        );

        $manager = new TopologyManager($topology);
        $order = $manager->resolveExchangeOrder();

        // "unrouted" must come before "commerce"
        $this->assertCount(2, $order);
        $unroutedIndex = array_search('unrouted', $order, true);
        $commerceIndex = array_search('commerce', $order, true);
        $this->assertLessThan($commerceIndex, $unroutedIndex);
    }

    public function testResolveExchangeOrderWithChainedDependencies(): void
    {
        $topology = $this->createTopology(
            exchanges: [
                'a' => ['type' => 'topic', 'durable' => true, 'arguments' => ['alternate-exchange' => 'b']],
                'b' => ['type' => 'fanout', 'durable' => true, 'arguments' => ['alternate-exchange' => 'c']],
                'c' => ['type' => 'fanout', 'durable' => true, 'arguments' => []],
            ],
        );

        $manager = new TopologyManager($topology);
        $order = $manager->resolveExchangeOrder();

        // c → b → a
        $this->assertCount(3, $order);
        $cIndex = array_search('c', $order, true);
        $bIndex = array_search('b', $order, true);
        $aIndex = array_search('a', $order, true);
        $this->assertLessThan($bIndex, $cIndex);
        $this->assertLessThan($aIndex, $bIndex);
    }

    public function testResolveExchangeOrderDetectsCycle(): void
    {
        $topology = $this->createTopology(
            exchanges: [
                'a' => ['type' => 'topic', 'durable' => true, 'arguments' => ['alternate-exchange' => 'b']],
                'b' => ['type' => 'fanout', 'durable' => true, 'arguments' => ['alternate-exchange' => 'a']],
            ],
        );

        $manager = new TopologyManager($topology);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cycle detected');
        $manager->resolveExchangeOrder();
    }

    public function testResolveExchangeOrderIgnoresExternalReferences(): void
    {
        // alternate-exchange references an exchange not in the topology — ignored
        $topology = $this->createTopology(
            exchanges: [
                'commerce' => ['type' => 'topic', 'durable' => true, 'arguments' => ['alternate-exchange' => 'external']],
            ],
        );

        $manager = new TopologyManager($topology);
        $order = $manager->resolveExchangeOrder();

        $this->assertSame(['commerce'], $order);
    }

    public function testResolveExchangeOrderWithEmptyExchanges(): void
    {
        $topology = $this->createTopology(exchanges: []);
        $manager = new TopologyManager($topology);

        $this->assertSame([], $manager->resolveExchangeOrder());
    }

    public function testDryRunWithFullTopology(): void
    {
        $topology = $this->createTopology(
            exchanges: [
                'commerce' => ['type' => 'topic', 'durable' => true, 'arguments' => []],
                'dlx' => ['type' => 'direct', 'durable' => true, 'arguments' => []],
            ],
            queues: [
                'orders_queue' => ['durable' => true, 'arguments' => ['x-dead-letter-exchange' => 'dlx']],
            ],
            bindings: [
                ['exchange' => 'commerce', 'queue' => 'orders_queue', 'binding_key' => 'order.*', 'arguments' => []],
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
                'events' => ['type' => 'fanout', 'durable' => true, 'arguments' => []],
            ],
            queues: [
                'all_events' => ['durable' => true, 'arguments' => []],
            ],
            bindings: [
                ['exchange' => 'events', 'queue' => 'all_events', 'binding_key' => '', 'arguments' => []],
            ],
        );

        $manager = new TopologyManager($topology);
        $actions = $manager->dryRun();

        $bindingAction = $actions[2]; // after exchange + queue
        $this->assertStringContainsString('(empty)', $bindingAction);
    }

    public function testNormaliseArgumentsCastsIntegerKeys(): void
    {
        $arguments = [
            'x-message-ttl' => '86400000',
            'x-max-length' => '100000',
            'x-max-length-bytes' => '104857600',
            'x-max-priority' => '10',
            'x-expires' => '604800000',
            'x-delivery-limit' => '5',
            'x-queue-type' => 'quorum',    // should NOT be cast
            'x-dead-letter-exchange' => 'dlx', // should NOT be cast
        ];

        $normalised = TopologyManager::normaliseArguments($arguments);

        $this->assertSame(86400000, $normalised['x-message-ttl']);
        $this->assertSame(100000, $normalised['x-max-length']);
        $this->assertSame(104857600, $normalised['x-max-length-bytes']);
        $this->assertSame(10, $normalised['x-max-priority']);
        $this->assertSame(604800000, $normalised['x-expires']);
        $this->assertSame(5, $normalised['x-delivery-limit']);
        $this->assertSame('quorum', $normalised['x-queue-type']);
        $this->assertSame('dlx', $normalised['x-dead-letter-exchange']);
    }

    public function testNormaliseArgumentsWithAlreadyIntegerValues(): void
    {
        $arguments = [
            'x-delivery-limit' => 5,
            'x-message-ttl' => 60000,
        ];

        $normalised = TopologyManager::normaliseArguments($arguments);

        $this->assertSame(5, $normalised['x-delivery-limit']);
        $this->assertSame(60000, $normalised['x-message-ttl']);
    }

    public function testNormaliseArgumentsWithEmptyArray(): void
    {
        $this->assertSame([], TopologyManager::normaliseArguments([]));
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
