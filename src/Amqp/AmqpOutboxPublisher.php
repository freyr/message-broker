<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Amqp;

use Freyr\MessageBroker\Amqp\Routing\AmqpRoutingStrategyInterface;
use Freyr\MessageBroker\Outbox\OutboxPublisherInterface;
use Freyr\MessageBroker\Stamp\MessageIdStamp;
use Freyr\MessageBroker\Stamp\MessageNameStamp;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * AMQP implementation of OutboxPublisherInterface.
 *
 * Publishes outbox events to RabbitMQ via a sender locator.
 * Derives routing (sender, routing key, headers) from the routing strategy.
 */
final readonly class AmqpOutboxPublisher implements OutboxPublisherInterface
{
    /**
     * @param ContainerInterface $senderLocator Keyed by sender/transport name (e.g. 'amqp', 'commerce')
     */
    public function __construct(
        private ContainerInterface $senderLocator,
        private AmqpRoutingStrategyInterface $routingStrategy,
        private LoggerInterface $logger,
    ) {}

    public function publish(Envelope $envelope): void
    {
        $event = $envelope->getMessage();

        $messageNameStamp = $envelope->last(MessageNameStamp::class);
        if (!$messageNameStamp instanceof MessageNameStamp) {
            throw new RuntimeException(sprintf(
                'Envelope for %s missing MessageNameStamp. '
                . 'Ensure OutboxPublishingMiddleware runs before the publisher.',
                $event::class,
            ));
        }
        $messageName = $messageNameStamp->messageName;

        $messageIdStamp = $envelope->last(MessageIdStamp::class);
        if (!$messageIdStamp instanceof MessageIdStamp) {
            throw new RuntimeException(sprintf(
                'Envelope for %s missing MessageIdStamp.',
                $event::class,
            ));
        }

        $senderName = $this->routingStrategy->getSenderName($event, $messageName);

        if (!$this->senderLocator->has($senderName)) {
            throw new RuntimeException(sprintf(
                'No AMQP sender "%s" configured for %s. '
                . 'Register the transport in the AmqpOutboxPublisher sender locator.',
                $senderName,
                $event::class,
            ));
        }

        $routingKey = $this->routingStrategy->getRoutingKey($event, $messageName);
        $headers = $this->routingStrategy->getHeaders($messageName);

        // Forward all stamps from a publisher envelope, add an AMQP-specific stamp
        $amqpEnvelope = $envelope->with(
            new AmqpStamp($routingKey, AMQP_NOPARAM, $headers),
        );

        $this->logger->debug('Publishing event to AMQP', [
            'message_name' => $messageName,
            'message_id' => $messageIdStamp->messageId,
            'event_class' => $event::class,
            'sender' => $senderName,
            'routing_key' => $routingKey,
        ]);

        /** @var SenderInterface $sender */
        $sender = $this->senderLocator->get($senderName);
        $sender->send($amqpEnvelope);
    }
}
