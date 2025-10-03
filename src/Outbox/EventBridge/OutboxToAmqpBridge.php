<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\EventBridge;

use Psr\Log\LoggerInterface;
use Freyr\MessageBroker\Outbox\Publishing\PublishingStrategyRegistry;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Outbox to AMQP Bridge.
 *
 * Generic handler for all outbox events. Uses strategy registry to find appropriate
 * publishing strategy for each event. Unmatched events are routed to DLQ.
 */
final readonly class OutboxToAmqpBridge
{
    public function __construct(
        private PublishingStrategyRegistry $strategyRegistry,
        private MessageBusInterface $eventBus,
        private LoggerInterface $logger,
        private string $dlqTransportName = 'dlq',
    ) {
    }

    /**
     * Generic handler for all outbox messages.
     * Uses strategy registry to publish or route to DLQ.
     */
    #[AsMessageHandler(fromTransport: 'outbox')]
    public function __invoke(object $event): void
    {
        $strategy = $this->strategyRegistry->findStrategyFor($event);

        if ($strategy === null) {
            $this->sendToDlq($event);
            return;
        }

        try {
            $strategy->publish($event);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to publish event via strategy', [
                'event_class' => $event::class,
                'strategy' => $strategy->getName(),
                'error' => $e->getMessage(),
            ]);

            // Re-throw to let Messenger handle retry/failed transport
            throw $e;
        }
    }

    /**
     * Send unmatched event to DLQ for manual inspection.
     */
    private function sendToDlq(object $event): void
    {
        $this->logger->warning('No publishing strategy found, routing to DLQ', [
            'event_class' => $event::class,
            'dlq_transport' => $this->dlqTransportName,
        ]);

        $envelope = new Envelope($event, [
            new TransportNamesStamp([$this->dlqTransportName]),
        ]);

        $this->eventBus->dispatch($envelope);
    }
}
