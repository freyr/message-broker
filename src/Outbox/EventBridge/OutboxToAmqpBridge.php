<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\EventBridge;

use Freyr\MessageBroker\Outbox\MessageName;
use Freyr\MessageBroker\Outbox\OutboxMessage;
use Freyr\MessageBroker\Outbox\Routing\AmqpRoutingStrategyInterface;
use Freyr\MessageBroker\Stamp\MessageIdStamp;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Outbox to AMQP Bridge (middleware).
 *
 * Intercepts OutboxMessage envelopes consumed from the outbox transport and
 * publishes them to AMQP via a sender locator. The routing strategy determines
 * which sender (transport/exchange) to use, the routing key, and headers.
 *
 * Reads the existing MessageIdStamp (added at dispatch time by
 * MessageIdStampMiddleware) to guarantee stable message IDs across redelivery.
 *
 * Short-circuits after sending: HandleMessageMiddleware has no handler for
 * OutboxMessage, so calling $stack->next() would throw NoHandlerForMessageException.
 */
final readonly class OutboxToAmqpBridge implements MiddlewareInterface
{
    /**
     * @param ContainerInterface $senderLocator Service locator keyed by transport name (e.g. 'amqp', 'commerce')
     */
    public function __construct(
        private ContainerInterface $senderLocator,
        private AmqpRoutingStrategyInterface $routingStrategy,
        private LoggerInterface $logger,
        private string $outboxTransportName = 'outbox',
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$envelope->getMessage() instanceof OutboxMessage) {
            return $stack->next()
                ->handle($envelope, $stack);
        }

        $receivedStamp = $envelope->last(ReceivedStamp::class);
        if (!$receivedStamp instanceof ReceivedStamp
            || $receivedStamp->getTransportName() !== $this->outboxTransportName) {
            return $stack->next()
                ->handle($envelope, $stack);
        }

        $event = $envelope->getMessage();

        $messageName = MessageName::fromClass($event)
            ?? throw new RuntimeException(sprintf('Event %s must have #[MessageName] attribute', $event::class));

        $messageIdStamp = $envelope->last(MessageIdStamp::class)
            ?? throw new RuntimeException(sprintf(
                'OutboxMessage %s consumed from outbox transport without MessageIdStamp. Ensure MessageIdStampMiddleware runs before outbox transport storage, or drain the outbox of legacy messages before deployment.',
                $event::class,
            ));

        $senderName = $this->routingStrategy->getSenderName($event);

        if (!$this->senderLocator->has($senderName)) {
            throw new RuntimeException(sprintf(
                'No sender "%s" configured for %s. Register the transport in the OutboxToAmqpBridge sender locator.',
                $senderName,
                $event::class,
            ));
        }

        /** @var SenderInterface $sender */
        $sender = $this->senderLocator->get($senderName);

        $routingKey = $this->routingStrategy->getRoutingKey($event, $messageName);
        $headers = $this->routingStrategy->getHeaders($messageName);

        $amqpEnvelope = new Envelope($event, [
            $messageIdStamp,
            new AmqpStamp($routingKey, AMQP_NOPARAM, $headers),
        ]);

        $this->logger->info('Publishing event to AMQP', [
            'message_name' => $messageName,
            'message_id' => $messageIdStamp->messageId,
            'event_class' => $event::class,
            'sender' => $senderName,
            'routing_key' => $routingKey,
        ]);

        $sender->send($amqpEnvelope);

        // Short-circuit: OutboxMessage is fully handled by this middleware.
        // HandleMessageMiddleware has no handler for OutboxMessage — calling
        // $stack->next() would throw NoHandlerForMessageException.
        return $envelope;
    }
}
