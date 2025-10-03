<?php

declare(strict_types=1);

namespace Freyr\Messenger\Outbox\Publishing;

use Psr\Log\LoggerInterface;
use Freyr\Messenger\Outbox\Routing\AmqpRoutingStrategyInterface;
use Freyr\Messenger\Outbox\Serializer\OutboxEventSerializer;
use RuntimeException;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * AMQP Publishing Strategy.
 *
 * Publishes events to AMQP/RabbitMQ using configured routing strategy.
 * Supports all events by default (acts as catch-all strategy).
 */
final readonly class AmqpPublishingStrategy implements PublishingStrategyInterface
{
    public function __construct(
        private MessageBusInterface $eventBus,
        private AmqpRoutingStrategyInterface $routingStrategy,
        private OutboxEventSerializer $serializer,
        private LoggerInterface $logger,
    ) {
    }

    public function supports(object $event): bool
    {
        // This strategy supports all events by default
        // In production, you might want to check for specific interfaces or attributes
        return true;
    }

    public function publish(object $event): void
    {
        // Extract message name from serializer
        $encoded = $this->serializer->encode(new Envelope($event));

        if (!isset($encoded['headers']) || !is_array($encoded['headers'])) {
            throw new RuntimeException('Invalid encoded message format');
        }

        $messageName = $encoded['headers']['message_name'] ?? null;

        if (!is_string($messageName)) {
            $this->logger->error('Cannot extract message_name from message', [
                'message_class' => $event::class,
            ]);
            throw new RuntimeException('Cannot extract message_name from message');
        }

        // Get routing configuration (pass event for attribute-based overrides)
        $exchange = $this->routingStrategy->getExchange($event, $messageName);
        $routingKey = $this->routingStrategy->getRoutingKey($event, $messageName);
        $headers = $this->routingStrategy->getHeaders($messageName);

        // Create AMQP stamp with routing configuration
        $amqpStamp = new AmqpStamp($routingKey, AMQP_NOPARAM, $headers);

        // Force dispatch to AMQP transport only
        $envelope = new Envelope($event, [
            $amqpStamp,
            new TransportNamesStamp(['amqp']),
        ]);

        $this->logger->info('Publishing event to AMQP', [
            'message_name' => $messageName,
            'exchange' => $exchange,
            'routing_key' => $routingKey,
        ]);

        $this->eventBus->dispatch($envelope);
    }

    public function getName(): string
    {
        return 'amqp';
    }
}
