<?php

declare(strict_types=1);

namespace Freyr\Messenger\Tests\Fixtures;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * AMQP Test Setup Helper.
 *
 * Configures exchanges, queues, and bindings for integration tests.
 */
final class AmqpTestSetup
{
    /**
     * Setup AMQP infrastructure for tests.
     *
     * Creates:
     * - order.placed exchange (topic)
     * - sla.events exchange (topic) - custom exchange for SLA events
     * - user.premium exchange (topic)
     * - test.inbox queue - bound to all test exchanges
     */
    public static function setup(AMQPStreamConnection $connection): void
    {
        $channel = $connection->channel();

        // Declare exchanges
        self::declareExchanges($channel);

        // Declare queues
        self::declareQueues($channel);

        // Create bindings
        self::createBindings($channel);

        $channel->close();
    }

    /**
     * Clean up AMQP infrastructure after tests.
     */
    public static function tearDown(AMQPStreamConnection $connection): void
    {
        $channel = $connection->channel();

        // Delete queues
        $channel->queue_delete('test.inbox');

        // Delete exchanges
        $channel->exchange_delete('order.placed');
        $channel->exchange_delete('sla.events');
        $channel->exchange_delete('user.premium');

        $channel->close();
    }

    private static function declareExchanges(AMQPChannel $channel): void
    {
        // Exchange for order events (default convention)
        $channel->exchange_declare(
            exchange: 'order.placed',
            type: 'topic',
            passive: false,
            durable: true,
            auto_delete: false
        );

        // Exchange for SLA events (custom via #[AmqpExchange])
        $channel->exchange_declare(
            exchange: 'sla.events',
            type: 'topic',
            passive: false,
            durable: true,
            auto_delete: false
        );

        // Exchange for user events (default convention)
        $channel->exchange_declare(
            exchange: 'user.premium',
            type: 'topic',
            passive: false,
            durable: true,
            auto_delete: false
        );
    }

    private static function declareQueues(AMQPChannel $channel): void
    {
        // Single test queue that receives all test messages
        $channel->queue_declare(
            queue: 'test.inbox',
            passive: false,
            durable: true,
            exclusive: false,
            auto_delete: false
        );
    }

    private static function createBindings(AMQPChannel $channel): void
    {
        // Bind test.inbox to order.placed exchange
        $channel->queue_bind(
            queue: 'test.inbox',
            exchange: 'order.placed',
            routing_key: 'order.#'  // All order events
        );

        // Bind test.inbox to sla.events exchange
        $channel->queue_bind(
            queue: 'test.inbox',
            exchange: 'sla.events',
            routing_key: 'sla.#'  // All SLA events
        );

        // Bind test.inbox to user.premium exchange
        $channel->queue_bind(
            queue: 'test.inbox',
            exchange: 'user.premium',
            routing_key: 'user.*.upgraded'  // Only upgraded events (matches routing key override)
        );
    }
}
