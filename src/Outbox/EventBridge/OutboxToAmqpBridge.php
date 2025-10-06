<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\EventBridge;

use Psr\Log\LoggerInterface;
use Freyr\MessageBroker\Outbox\Routing\AmqpRoutingStrategyInterface;
use Freyr\MessageBroker\Outbox\Serializer\OutboxSerializer;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Outbox to AMQP Bridge.
 *
 * Consumes events from the outbox transport and publishes them to AMQP/RabbitMQ.
 * Uses routing strategy to determine exchange, routing key, and headers.
 */
final readonly class OutboxToAmqpBridge
{
    public function __construct(
        private MessageBusInterface $eventBus,
        private AmqpRoutingStrategyInterface $routingStrategy,
        private OutboxSerializer $serializer,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Handle outbox event and publish to AMQP.
     */
    #[AsMessageHandler(fromTransport: 'outbox')]
    public function __invoke(object $event): void
    {
        // Extract message name from serializer
        $encoded = $this->serializer->encode(new Envelope($event));

        if (!isset($encoded['headers']) || !is_array($encoded['headers'])) {
            throw new RuntimeException('Invalid encoded message format');
        }

        $messageName = $encoded['headers']['message_name'] ?? null;

        if (!is_string($messageName)) {
            $this->logger->error('Cannot extract message_name from event', [
                'event_class' => $event::class,
            ]);
            throw new RuntimeException('Cannot extract message_name from event');
        }

        // Get AMQP routing configuration
        $exchange = $this->routingStrategy->getExchange($event, $messageName);
        $routingKey = $this->routingStrategy->getRoutingKey($event, $messageName);
        $headers = $this->routingStrategy->getHeaders($messageName);

        // Create AMQP stamp with routing configuration
        $amqpStamp = new AmqpStamp($routingKey, AMQP_NOPARAM, $headers);

        // Dispatch to AMQP transport
        $envelope = new Envelope($event, [
            $amqpStamp,
            new TransportNamesStamp(['amqp']),
        ]);

        $this->logger->info('Publishing event to AMQP', [
            'message_name' => $messageName,
            'event_class' => $event::class,
            'exchange' => $exchange,
            'routing_key' => $routingKey,
        ]);

        $this->eventBus->dispatch($envelope);
    }
}
