<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\Routing;

/**
 * AMQP Routing Strategy Interface.
 *
 * Determines AMQP routing parameters (exchange, routing key) for messages.
 * Supports attribute-based overrides via #[AmqpExchange] and #[AmqpRoutingKey].
 */
interface AmqpRoutingStrategyInterface
{
    /**
     * Get AMQP exchange name for an event.
     *
     * Default: First 2 parts of message name (e.g., 'order.placed' → 'order.placed')
     * Override: Use #[AmqpExchange('custom.exchange')] on event class
     *
     * @param object $event Domain event instance
     * @param string $messageName Message name from #[MessageName] attribute
     */
    public function getExchange(object $event, string $messageName): string;

    /**
     * Get AMQP routing key for an event.
     *
     * Default: Full message name (e.g., 'order.placed')
     * Override: Use #[AmqpRoutingKey('custom.key')] on event class
     *
     * @param object $event Domain event instance
     * @param string $messageName Message name from #[MessageName] attribute
     */
    public function getRoutingKey(object $event, string $messageName): string;

    /**
     * Get additional AMQP headers for a message.
     *
     * @param string $messageName Message name from #[MessageName] attribute (e.g., 'order.placed')
     * @return array<string, mixed>
     */
    public function getHeaders(string $messageName): array;
}
