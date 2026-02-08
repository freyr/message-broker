<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Amqp;

use Freyr\MessageBroker\Amqp\DefinitionsFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DefinitionsFormatter.
 *
 * Tests RabbitMQ definitions JSON generation including field mapping,
 * vhost handling, default values, and integer preservation.
 */
final class DefinitionsFormatterTest extends TestCase
{
    public function testFormatExchanges(): void
    {
        $formatter = new DefinitionsFormatter([
            'exchanges' => [
                'commerce' => ['type' => 'topic', 'durable' => true, 'arguments' => ['alternate-exchange' => 'unrouted']],
            ],
            'queues' => [],
            'bindings' => [],
        ]);

        $result = $formatter->format('/');

        $this->assertCount(1, $result['exchanges']);
        $exchange = $result['exchanges'][0];
        $this->assertSame('commerce', $exchange['name']);
        $this->assertSame('/', $exchange['vhost']);
        $this->assertSame('topic', $exchange['type']);
        $this->assertTrue($exchange['durable']);
        $this->assertFalse($exchange['auto_delete']);
        $this->assertFalse($exchange['internal']);
        $this->assertEquals((object) ['alternate-exchange' => 'unrouted'], $exchange['arguments']);
    }

    public function testFormatQueues(): void
    {
        $formatter = new DefinitionsFormatter([
            'exchanges' => [],
            'queues' => [
                'orders_queue' => [
                    'durable' => true,
                    'arguments' => [
                        'x-dead-letter-exchange' => 'dlx',
                        'x-queue-type' => 'quorum',
                        'x-delivery-limit' => 5,
                    ],
                ],
            ],
            'bindings' => [],
        ]);

        $result = $formatter->format('/');

        $this->assertCount(1, $result['queues']);
        $queue = $result['queues'][0];
        $this->assertSame('orders_queue', $queue['name']);
        $this->assertSame('/', $queue['vhost']);
        $this->assertTrue($queue['durable']);
        $this->assertFalse($queue['auto_delete']);

        $args = (array) $queue['arguments'];
        $this->assertSame('dlx', $args['x-dead-letter-exchange']);
        $this->assertSame('quorum', $args['x-queue-type']);
        $this->assertSame(5, $args['x-delivery-limit']);
    }

    public function testFormatBindingsMapsBindingKeyToRoutingKey(): void
    {
        $formatter = new DefinitionsFormatter([
            'exchanges' => [],
            'queues' => [],
            'bindings' => [
                ['exchange' => 'commerce', 'queue' => 'orders_queue', 'binding_key' => 'order.*', 'arguments' => []],
            ],
        ]);

        $result = $formatter->format('/');

        $this->assertCount(1, $result['bindings']);
        $binding = $result['bindings'][0];
        $this->assertSame('commerce', $binding['source']);
        $this->assertSame('/', $binding['vhost']);
        $this->assertSame('orders_queue', $binding['destination']);
        $this->assertSame('queue', $binding['destination_type']);
        $this->assertSame('order.*', $binding['routing_key']);
        $this->assertEquals((object) [], $binding['arguments']);
    }

    public function testFormatAppliesVhostToAllEntries(): void
    {
        $formatter = new DefinitionsFormatter([
            'exchanges' => [
                'ex' => ['type' => 'direct', 'durable' => true, 'arguments' => []],
            ],
            'queues' => [
                'q' => ['durable' => true, 'arguments' => []],
            ],
            'bindings' => [
                ['exchange' => 'ex', 'queue' => 'q', 'binding_key' => '', 'arguments' => []],
            ],
        ]);

        $result = $formatter->format('my-vhost');

        $this->assertSame('my-vhost', $result['exchanges'][0]['vhost']);
        $this->assertSame('my-vhost', $result['queues'][0]['vhost']);
        $this->assertSame('my-vhost', $result['bindings'][0]['vhost']);
    }

    public function testFormatEmptyTopology(): void
    {
        $formatter = new DefinitionsFormatter([
            'exchanges' => [],
            'queues' => [],
            'bindings' => [],
        ]);

        $result = $formatter->format('/');

        $this->assertSame([], $result['exchanges']);
        $this->assertSame([], $result['queues']);
        $this->assertSame([], $result['bindings']);
    }

    public function testFormatPreservesIntegerArguments(): void
    {
        $formatter = new DefinitionsFormatter([
            'exchanges' => [],
            'queues' => [
                'q' => [
                    'durable' => true,
                    'arguments' => [
                        'x-message-ttl' => '86400000',
                        'x-delivery-limit' => '5',
                    ],
                ],
            ],
            'bindings' => [],
        ]);

        $result = $formatter->format('/');
        $args = (array) $result['queues'][0]['arguments'];

        // Integer arguments should be normalised
        $this->assertSame(86400000, $args['x-message-ttl']);
        $this->assertSame(5, $args['x-delivery-limit']);
    }

    public function testFormatProducesValidJson(): void
    {
        $formatter = new DefinitionsFormatter([
            'exchanges' => [
                'commerce' => ['type' => 'topic', 'durable' => true, 'arguments' => []],
            ],
            'queues' => [
                'orders' => ['durable' => true, 'arguments' => ['x-queue-type' => 'quorum']],
            ],
            'bindings' => [
                ['exchange' => 'commerce', 'queue' => 'orders', 'binding_key' => 'order.*', 'arguments' => []],
            ],
        ]);

        $result = $formatter->format('/');
        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('exchanges', $decoded);
        $this->assertArrayHasKey('queues', $decoded);
        $this->assertArrayHasKey('bindings', $decoded);
    }

    public function testFormatExchangeWithEmptyArguments(): void
    {
        $formatter = new DefinitionsFormatter([
            'exchanges' => [
                'simple' => ['type' => 'fanout', 'durable' => false, 'arguments' => []],
            ],
            'queues' => [],
            'bindings' => [],
        ]);

        $result = $formatter->format('/');
        $exchange = $result['exchanges'][0];

        $this->assertFalse($exchange['durable']);
        $this->assertEquals((object) [], $exchange['arguments']);
    }
}
