<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use Freyr\MessageBroker\Stamp\MessageIdStamp;
use Freyr\MessageBroker\Stamp\MessageNameStamp;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Generic outbox publishing middleware.
 *
 * Intercepts OutboxMessage envelopes consumed from outbox transports and
 * delegates to transport-specific publishers via a service locator.
 *
 * Short-circuits after sending: HandleMessageMiddleware has no handler for
 * OutboxMessage, so calling $stack->next() would throw NoHandlerForMessageException.
 */
final readonly class OutboxPublishingMiddleware implements MiddlewareInterface
{
    /**
     * @param ContainerInterface $publisherLocator Keyed by outbox transport name
     */
    public function __construct(
        private ContainerInterface $publisherLocator,
        private LoggerInterface $logger,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$envelope->getMessage() instanceof OutboxMessage) {
            return $stack->next()
                ->handle($envelope, $stack);
        }

        $receivedStamp = $envelope->last(ReceivedStamp::class);
        if (!$receivedStamp instanceof ReceivedStamp) {
            return $stack->next()
                ->handle($envelope, $stack);
        }

        $transportName = $receivedStamp->getTransportName();

        if (!$this->publisherLocator->has($transportName)) {
            $this->logger->debug('No outbox publisher registered for transport, passing through.', [
                'transport' => $transportName,
            ]);

            return $stack->next()
                ->handle($envelope, $stack);
        }

        $event = $envelope->getMessage();

        $messageName = MessageName::fromClass($event)
            ?? throw new RuntimeException(sprintf('Event %s must have #[MessageName] attribute.', $event::class));

        $messageIdStamp = $envelope->last(MessageIdStamp::class)
            ?? throw new RuntimeException(sprintf(
                'OutboxMessage %s consumed without MessageIdStamp. Ensure MessageIdStampMiddleware runs at dispatch time.',
                $event::class,
            ));

        // Build clean envelope for publisher (strip transport stamps)
        $publishEnvelope = new Envelope($event, [$messageIdStamp, new MessageNameStamp($messageName)]);

        /** @var OutboxPublisherInterface $publisher */
        $publisher = $this->publisherLocator->get($transportName);
        $publisher->publish($publishEnvelope);

        // Short-circuit: OutboxMessage is fully handled by this middleware.
        return $envelope;
    }
}
