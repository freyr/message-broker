<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\EventBridge;

use Freyr\Identity\Id;
use Freyr\MessageBroker\Inbox\MessageIdStamp;
use Freyr\MessageBroker\Outbox\MessageName;
use Freyr\MessageBroker\Outbox\Routing\AmqpRoutingStrategyInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Outbox to AMQP Bridge.
 *
 * Adds MessageIdStamp to envelope (serialized to headers automatically by Symfony).
 * Stamps are transported via X-Message-Stamp-* headers natively.
 */
final readonly class OutboxToAmqpBridge
{
    public function __construct(
        private MessageBusInterface $eventBus,
        private AmqpRoutingStrategyInterface $routingStrategy,
        private LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler(fromTransport: 'outbox')]
    public function __invoke(OutboxMessage $event): void
    {
        // Extract message name
        $messageName = $this->extractMessageName($event);

        // Generate messageId for this publishing (UUID v7 for ordering)
        $messageId = Id::new();

        // Get AMQP routing
        $exchange = $this->routingStrategy->getExchange($event, $messageName);
        $routingKey = $this->routingStrategy->getRoutingKey($event, $messageName);
        $headers = $this->routingStrategy->getHeaders($messageName);

        // Create envelope with stamps
        // MessageIdStamp will be automatically serialized to X-Message-Stamp-MessageIdStamp header
        $envelope = new Envelope($event, [
            new MessageIdStamp($messageId->__toString()),
            new AmqpStamp($routingKey, AMQP_NOPARAM, $headers),
            new TransportNamesStamp(['amqp']),
        ]);

        $this->logger->info('Publishing event to AMQP', [
            'message_name' => $messageName,
            'message_id' => $messageId->__toString(),
            'event_class' => $event::class,
            'exchange' => $exchange,
            'routing_key' => $routingKey,
        ]);

        $this->eventBus->dispatch($envelope);
    }

    private function extractMessageName(OutboxMessage $event): string
    {
        $reflection = new \ReflectionClass($event);
        $attributes = $reflection->getAttributes(MessageName::class);

        if (empty($attributes)) {
            throw new \RuntimeException(sprintf('Event %s must have #[MessageName] attribute', $event::class));
        }

        /** @var MessageName $messageNameAttr */
        $messageNameAttr = $attributes[0]->newInstance();

        return $messageNameAttr->name;
    }

}
