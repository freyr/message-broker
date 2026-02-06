<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\Routing;

/**
 * Default AMQP Routing Strategy.
 *
 * Routes messages based on default Symfony Messenger behaviour.
 * Exchange is statically configured in the transport.
 *
 * Routing key is determined by the message name:
 * - order.placed → routing_key: order.placed
 * - sla.calculation.started → routing_key: sla.calculation.started
 * - user.account.created → routing_key: user.account.created
 *
 * Override with attributes:
 * - #[MessengerTransport('custom.transport')]
 * - #[AmqpRoutingKey('custom.account.key')]
 */
final readonly class DefaultAmqpRoutingStrategy implements AmqpRoutingStrategyInterface
{
    public function getTransport(object $event): string
    {
        return MessengerTransport::fromClass($event) ?? 'amqp';
    }

    public function getRoutingKey(object $event, string $messageName): string
    {
        return AmqpRoutingKey::fromClass($event) ?? $messageName;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(string $messageName): array
    {
        $parts = explode('.', $messageName);

        return [
            'x-message-name' => $messageName,
            'x-message-domain' => $parts[0],
            'x-message-subdomain' => $parts[1] ?? 'unknown',
            'x-message-action' => $parts[2] ?? 'unknown',
        ];
    }
}
