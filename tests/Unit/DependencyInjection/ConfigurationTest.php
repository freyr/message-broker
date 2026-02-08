<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\DependencyInjection;

use Freyr\MessageBroker\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

/**
 * Unit tests for Configuration tree builder.
 *
 * Tests AMQP topology configuration validation, defaults, and error cases.
 */
final class ConfigurationTest extends TestCase
{
    public function testEmptyTopologyIsValid(): void
    {
        $config = $this->processConfig([]);

        $this->assertSame([], $config['amqp']['topology']['exchanges']);
        $this->assertSame([], $config['amqp']['topology']['queues']);
        $this->assertSame([], $config['amqp']['topology']['bindings']);
    }

    public function testExchangeRequiresType(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $this->processConfig([
            'amqp' => [
                'topology' => [
                    'exchanges' => [
                        'commerce' => [
                            'durable' => true,
                            // missing 'type'
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testExchangeTypeValidation(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $this->processConfig([
            'amqp' => [
                'topology' => [
                    'exchanges' => [
                        'commerce' => [
                            'type' => 'invalid_type',
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testExchangeDefaultValues(): void
    {
        $config = $this->processConfig([
            'amqp' => [
                'topology' => [
                    'exchanges' => [
                        'commerce' => [
                            'type' => 'topic',
                        ],
                    ],
                ],
            ],
        ]);

        $exchange = $config['amqp']['topology']['exchanges']['commerce'];
        $this->assertSame('topic', $exchange['type']);
        $this->assertTrue($exchange['durable']);
        $this->assertSame([], $exchange['arguments']);
    }

    public function testAllExchangeTypesAccepted(): void
    {
        foreach (['direct', 'fanout', 'topic', 'headers'] as $type) {
            $config = $this->processConfig([
                'amqp' => [
                    'topology' => [
                        'exchanges' => [
                            'test' => [
                                'type' => $type,
                            ],
                        ],
                    ],
                ],
            ]);

            $this->assertSame($type, $config['amqp']['topology']['exchanges']['test']['type']);
        }
    }

    public function testQueueDefaultValues(): void
    {
        $config = $this->processConfig([
            'amqp' => [
                'topology' => [
                    'queues' => [
                        'orders_queue' => [],
                    ],
                ],
            ],
        ]);

        $queue = $config['amqp']['topology']['queues']['orders_queue'];
        $this->assertTrue($queue['durable']);
        $this->assertSame([], $queue['arguments']);
    }

    public function testQueueWithArguments(): void
    {
        $config = $this->processConfig([
            'amqp' => [
                'topology' => [
                    'queues' => [
                        'orders_queue' => [
                            'arguments' => [
                                'x-dead-letter-exchange' => 'dlx',
                                'x-queue-type' => 'quorum',
                                'x-delivery-limit' => 5,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $args = $config['amqp']['topology']['queues']['orders_queue']['arguments'];
        $this->assertSame('dlx', $args['x-dead-letter-exchange']);
        $this->assertSame('quorum', $args['x-queue-type']);
        $this->assertSame(5, $args['x-delivery-limit']);
    }

    public function testBindingDefaultValues(): void
    {
        $config = $this->processConfig([
            'amqp' => [
                'topology' => [
                    'bindings' => [
                        [
                            'exchange' => 'commerce',
                            'queue' => 'orders_queue',
                        ],
                    ],
                ],
            ],
        ]);

        $binding = $config['amqp']['topology']['bindings'][0];
        $this->assertSame('commerce', $binding['exchange']);
        $this->assertSame('orders_queue', $binding['queue']);
        $this->assertSame('', $binding['binding_key']);
        $this->assertSame([], $binding['arguments']);
    }

    public function testBindingWithAllFields(): void
    {
        $config = $this->processConfig([
            'amqp' => [
                'topology' => [
                    'bindings' => [
                        [
                            'exchange' => 'commerce',
                            'queue' => 'orders_queue',
                            'binding_key' => 'order.*',
                            'arguments' => [
                                'x-match' => 'any',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $binding = $config['amqp']['topology']['bindings'][0];
        $this->assertSame('order.*', $binding['binding_key']);
        $this->assertSame([
            'x-match' => 'any',
        ], $binding['arguments']);
    }

    public function testFullTopologyConfiguration(): void
    {
        $config = $this->processConfig([
            'amqp' => [
                'topology' => [
                    'exchanges' => [
                        'commerce' => [
                            'type' => 'topic',
                            'arguments' => [
                                'alternate-exchange' => 'unrouted',
                            ],
                        ],
                        'dlx' => [
                            'type' => 'direct',
                        ],
                        'unrouted' => [
                            'type' => 'fanout',
                        ],
                    ],
                    'queues' => [
                        'orders_queue' => [
                            'arguments' => [
                                'x-dead-letter-exchange' => 'dlx',
                                'x-queue-type' => 'quorum',
                            ],
                        ],
                        'dlq.orders' => [],
                    ],
                    'bindings' => [
                        [
                            'exchange' => 'commerce',
                            'queue' => 'orders_queue',
                            'binding_key' => 'order.*',
                        ],
                        [
                            'exchange' => 'dlx',
                            'queue' => 'dlq.orders',
                            'binding_key' => 'dlq.orders',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertCount(3, $config['amqp']['topology']['exchanges']);
        $this->assertCount(2, $config['amqp']['topology']['queues']);
        $this->assertCount(2, $config['amqp']['topology']['bindings']);
    }

    public function testExistingInboxConfigPreserved(): void
    {
        $config = $this->processConfig([
            'inbox' => [
                'message_types' => [
                    'order.placed' => 'App\\Message\\OrderPlaced',
                ],
                'deduplication_table_name' => 'custom_dedup',
            ],
            'amqp' => [
                'topology' => [
                    'exchanges' => [
                        'commerce' => [
                            'type' => 'topic',
                        ],
                    ],
                ],
            ],
        ]);

        // Inbox config should still work
        $this->assertSame([
            'order.placed' => 'App\\Message\\OrderPlaced',
        ], $config['inbox']['message_types']);
        $this->assertSame('custom_dedup', $config['inbox']['deduplication_table_name']);

        // AMQP config should work alongside
        $this->assertArrayHasKey('commerce', $config['amqp']['topology']['exchanges']);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function processConfig(array $config): array
    {
        $processor = new Processor();

        return $processor->processConfiguration(new Configuration(), [$config]);
    }
}
