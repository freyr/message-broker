<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Fixtures;

use Freyr\MessageBroker\Contracts\MessageIdStamp;
use Freyr\MessageBroker\Contracts\MessageNameStamp;
use Freyr\MessageBroker\Contracts\OutboxPublisherInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Simple test outbox publisher for functional tests.
 *
 * Publishes outbox envelopes to a configured Symfony Messenger sender.
 * Uses the message name as routing key (convention-based routing).
 */
final readonly class TestOutboxPublisher implements OutboxPublisherInterface
{
    public function __construct(
        private ContainerInterface $senderLocator,
    ) {}

    public function publish(Envelope $envelope): void
    {
        $messageNameStamp = $envelope->last(MessageNameStamp::class)
            ?? throw new RuntimeException('Envelope must contain MessageNameStamp.');

        $messageIdStamp = $envelope->last(MessageIdStamp::class)
            ?? throw new RuntimeException('Envelope must contain MessageIdStamp.');

        $routingKey = $messageNameStamp->messageName;

        /** @var SenderInterface $sender */
        $sender = $this->senderLocator->get('amqp');

        $sender->send(new Envelope($envelope->getMessage(), [
            $messageIdStamp,
            $messageNameStamp,
            new \Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp($routingKey),
        ]));
    }
}
