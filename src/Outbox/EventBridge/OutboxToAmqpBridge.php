<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\EventBridge;

use Freyr\Identity\Id;
use Freyr\MessageBroker\Stamp\MessageIdStamp;
use Freyr\MessageBroker\Outbox\MessageName;
use Freyr\MessageBroker\Outbox\Routing\AmqpRoutingStrategyInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Outbox to AMQP Bridge.
 *
 * Adds various additional headers to the outgoing message
 * Ensure application of custom behavior.
 *  - custom transport
 *  - custom routing key
 */
final readonly class OutboxToAmqpBridge
{
    public function __construct(
        private MessageBusInterface $eventBus,
        private AmqpRoutingStrategyInterface $routingStrategy,
        private LoggerInterface $logger,
    ) {}

    #[AsMessageHandler(fromTransport: 'outbox')]
    public function __invoke(OutboxMessage $event): void
    {
        // Extract message name (cached per class)
        $messageName = MessageName::fromClass($event)
            ?? throw new RuntimeException(sprintf('Event %s must have #[MessageName] attribute', $event::class));

        // Generate messageId for this publishing (UUID v7 for ordering)
        $messageId = Id::new();

        // Get AMQP routing
        $transport = $this->routingStrategy->getTransport($event);
        $routingKey = $this->routingStrategy->getRoutingKey($event, $messageName);
        $headers = $this->routingStrategy->getHeaders($messageName);

        // Create the envelope with stamps
        // MessageIdStamp will be automatically serialized to X-Message-Stamp-MessageIdStamp header
        $envelope = new Envelope($event, [
            new MessageIdStamp((string) $messageId),
            new AmqpStamp($routingKey, AMQP_NOPARAM, $headers),
            new TransportNamesStamp([$transport]),
        ]);

        $this->logger->info('Publishing event to AMQP', [
            'message_name' => $messageName,
            'message_id' => (string) $messageId,
            'event_class' => $event::class,
            'exchange' => $transport,
            'routing_key' => $routingKey,
        ]);

        $this->eventBus->dispatch($envelope);
    }
}
