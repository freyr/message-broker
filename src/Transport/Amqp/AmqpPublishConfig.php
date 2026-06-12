<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp;

/**
 * Transport-native relay/publish configuration: AMQP vocabulary only
 * (exchange, routing key strategy, confirms) — Kafka and SQS relays will
 * have entirely different config classes.
 */
final readonly class AmqpPublishConfig
{
    public function __construct(
        public string $exchange,
        public string $routingKeyTemplate = '{message_name}', // e.g. 'order.placed'
        public bool $publisherConfirms = true,
    ) {}
}
