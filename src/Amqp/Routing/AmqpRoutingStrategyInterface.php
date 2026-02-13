<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Amqp\Routing;

/**
 * AMQP Routing Strategy Interface.
 *
 * Determines AMQP routing parameters (sender/exchange, routing key, headers) for messages.
 */
interface AmqpRoutingStrategyInterface
{
    /**
     * Resolve the sender name (Symfony Messenger transport) for publishing.
     * The returned name must match a key in the AmqpOutboxPublisher sender locator.
     */
    public function getSenderName(object $event, string $messageName): string;

    /**
     * Resolve the AMQP routing key for the message.
     */
    public function getRoutingKey(object $event, string $messageName): string;

    /**
     * Resolve AMQP message headers.
     *
     * @return array<string, string>
     */
    public function getHeaders(string $messageName): array;
}
