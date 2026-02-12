<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\Routing;

/**
 * AMQP Routing Strategy Interface.
 *
 * Determines AMQP routing parameters (sender/exchange, routing key, headers) for messages.
 */
interface AmqpRoutingStrategyInterface
{
    /**
     * Resolve the sender name (Symfony transport) for the given event.
     *
     * The returned name must match a key in the OutboxToAmqpBridge sender locator.
     * Each transport is configured with its own AMQP exchange.
     */
    public function getSenderName(object $event): string;

    public function getRoutingKey(object $event, string $messageName): string;

    /** @return array<string, string> */
    public function getHeaders(string $messageName): array;
}
