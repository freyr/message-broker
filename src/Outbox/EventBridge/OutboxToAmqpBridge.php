<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\EventBridge;

use Freyr\Identity\Id;
use Freyr\MessageBroker\Inbox\MessageIdStamp;
use Freyr\MessageBroker\Outbox\MessageName;
use Freyr\MessageBroker\Outbox\Routing\AmqpRoutingStrategyInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;
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
    ) {}

    #[AsMessageHandler(fromTransport: 'outbox')]
    public function __invoke(object $event): void
    {
        // Extract message name and ID
        $messageName = $this->extractMessageName($event);
        $messageId = $this->extractMessageId($event);

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
            'event_class' => $event::class,
            'exchange' => $exchange,
            'routing_key' => $routingKey,
        ]);

        $this->eventBus->dispatch($envelope);
    }

    private function extractMessageName(object $event): string
    {
        $reflection = new ReflectionClass($event);
        $attributes = $reflection->getAttributes(MessageName::class);

        if (empty($attributes)) {
            throw new RuntimeException(
                sprintf('Event %s must have #[MessageName] attribute', $event::class)
            );
        }

        /** @var MessageName $messageNameAttr */
        $messageNameAttr = $attributes[0]->newInstance();

        return $messageNameAttr->name;
    }

    private function extractMessageId(object $event): Id
    {
        $reflection = new ReflectionClass($event);

        if (!$reflection->hasProperty('messageId')) {
            throw new RuntimeException(
                sprintf('Event %s must have a public messageId property of type Id', $event::class)
            );
        }

        $property = $reflection->getProperty('messageId');

        if (!$property->isPublic()) {
            throw new RuntimeException(
                sprintf('Property messageId in event %s must be public', $event::class)
            );
        }

        $messageId = $property->getValue($event);

        if (!$messageId instanceof Id) {
            throw new RuntimeException(
                sprintf('Property messageId in event %s must be of type %s, got %s',
                    $event::class,
                    Id::class,
                    get_debug_type($messageId)
                )
            );
        }

        return $messageId;
    }
}
