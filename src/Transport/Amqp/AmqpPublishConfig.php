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
        // MessageKey only with x-consistent-hash + SAC (near-FIFO); see RoutingKeyStrategy.
        public RoutingKeyStrategy $routingKey = RoutingKeyStrategy::MessageName,
        public bool $publisherConfirms = true,
    ) {}
}
