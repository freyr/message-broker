<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use Freyr\Identity\Id;
use Freyr\MessageBroker\Outbox\EventBridge\OutboxMessage;
use Freyr\MessageBroker\Stamp\MessageIdStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Stamps OutboxMessage envelopes with MessageIdStamp at dispatch time.
 *
 * This ensures the message ID is generated once and persisted with the outbox
 * record, surviving redelivery and guaranteeing stable deduplication downstream.
 */
final readonly class MessageIdStampMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$envelope->getMessage() instanceof OutboxMessage) {
            return $stack->next()->handle($envelope, $stack);
        }

        if ($envelope->last(ReceivedStamp::class) !== null) {
            return $stack->next()->handle($envelope, $stack);
        }

        if ($envelope->last(MessageIdStamp::class) === null) {
            $envelope = $envelope->with(new MessageIdStamp((string) Id::new()));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
