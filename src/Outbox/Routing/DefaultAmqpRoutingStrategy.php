<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\Routing;

/**
 * Default AMQP Routing Strategy.
 *
 * Routes messages based on default Symfony Messenger behaviour.
 *
 * Sender (exchange) is determined by #[AmqpExchange] attribute:
 * - No attribute → 'amqp' (default transport)
 * - #[AmqpExchange('commerce')] → 'commerce' transport
 *
 * Routing key is determined by the message name:
 * - order.placed → routing_key: order.placed
 * - sla.calculation.started → routing_key: sla.calculation.started
 *
 * Override with attributes:
 * - #[AmqpExchange('commerce')] — publish via a different transport/exchange
 * - #[AmqpRoutingKey('custom.key')] — override the routing key
 */
final readonly class DefaultAmqpRoutingStrategy implements AmqpRoutingStrategyInterface
{
    public function __construct(
        private string $defaultSenderName = 'amqp',
    ) {}

    public function getSenderName(object $event): string
    {
        return AmqpExchange::fromClass($event) ?? $this->defaultSenderName;
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
        return [
            'x-message-name' => $messageName,
        ];
    }
}
