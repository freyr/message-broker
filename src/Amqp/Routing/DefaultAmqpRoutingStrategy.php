<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Amqp\Routing;

/**
 * Convention-based AMQP routing from #[MessageName].
 *
 * Default behaviour:
 * - Sender: configurable default (e.g. 'amqp')
 * - Routing key: full message name (e.g. 'order.placed')
 * - Headers: x-message-name header
 *
 * Override via YAML config or #[AmqpExchange]/#[AmqpRoutingKey] attributes.
 * YAML overrides take precedence over attributes.
 */
final readonly class DefaultAmqpRoutingStrategy implements AmqpRoutingStrategyInterface
{
    /**
     * @param array<string, array{sender?: string, routing_key?: string}> $routingOverrides
     */
    public function __construct(
        private string $defaultSenderName = 'amqp',
        private array $routingOverrides = [],
    ) {}

    public function getSenderName(object $event, string $messageName): string
    {
        // 1. YAML override
        if (isset($this->routingOverrides[$messageName]['sender'])) {
            return $this->routingOverrides[$messageName]['sender'];
        }

        // 2. Attribute override (optional)
        $attributeSender = AmqpExchange::fromClass($event);
        if ($attributeSender !== null) {
            return $attributeSender;
        }

        // 3. Default sender
        return $this->defaultSenderName;
    }

    public function getRoutingKey(object $event, string $messageName): string
    {
        // 1. YAML override
        if (isset($this->routingOverrides[$messageName]['routing_key'])) {
            return $this->routingOverrides[$messageName]['routing_key'];
        }

        // 2. Attribute override (optional)
        $attributeKey = AmqpRoutingKey::fromClass($event);
        if ($attributeKey !== null) {
            return $attributeKey;
        }

        // 3. Convention: full message name as routing key
        return $messageName;
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
