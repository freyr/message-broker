<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\Routing;

use ReflectionClass;

/**
 * Default AMQP Routing Strategy.
 *
 * Routes messages based on first 2 parts of message name with attribute-based overrides:
 *
 * Default behavior:
 * - order.placed → exchange: order.placed, routing_key: order.placed
 * - sla.calculation.started → exchange: sla.calculation, routing_key: sla.calculation.started
 * - user.account.created → exchange: user.account, routing_key: user.account.created
 *
 * Override with attributes:
 * - #[AmqpExchange('custom.exchange')] - override exchange
 * - #[AmqpRoutingKey('custom.account.key')] - override routing key (supports wildcards)
 */
final readonly class DefaultAmqpRoutingStrategy implements AmqpRoutingStrategyInterface
{
    public function getExchange(object $event, string $messageName): string
    {
        // Check for #[AmqpExchange] attribute override
        $reflection = new ReflectionClass($event);
        $attributes = $reflection->getAttributes(AmqpExchange::class);

        if (!empty($attributes)) {
            /** @var AmqpExchange $exchangeAttr */
            $exchangeAttr = $attributes[0]->newInstance();
            return $exchangeAttr->name;
        }

        // Default: Extract first 2 parts from message name
        // order.placed → order.placed
        // sla.calculation.started → sla.calculation
        $parts = explode('.', $messageName);

        if (count($parts) < 2) {
            // Fallback for malformed message names
            return 'events';
        }

        return sprintf('%s.%s', $parts[0], $parts[1]);
    }

    public function getRoutingKey(object $event, string $messageName): string
    {
        // Check for #[AmqpRoutingKey] attribute override
        $reflection = new ReflectionClass($event);
        $attributes = $reflection->getAttributes(AmqpRoutingKey::class);

        if (!empty($attributes)) {
            /** @var AmqpRoutingKey $routingKeyAttr */
            $routingKeyAttr = $attributes[0]->newInstance();
            return $routingKeyAttr->key;
        }

        // Default: Use full message name as routing key
        return $messageName;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(string $messageName): array
    {
        $parts = explode('.', $messageName);

        return [
            'x-message-name' => $messageName,
            'x-message-domain' => $parts[0] ?? 'unknown',
            'x-message-subdomain' => $parts[1] ?? 'unknown',
            'x-message-action' => $parts[2] ?? 'unknown',
        ];
    }
}
