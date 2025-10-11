<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\Routing;

/**
 * AMQP Routing Strategy Interface.
 *
 * Determines AMQP routing parameters (exchange, routing key) for messages.
 */
interface AmqpRoutingStrategyInterface
{
    public function getTransport(object $event): string;

    public function getRoutingKey(object $event, string $messageName): string;

    public function getHeaders(string $messageName): array;
}
