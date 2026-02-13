<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Inbox;

use Freyr\MessageBroker\Stamp\MessageIdStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final readonly class DeduplicationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private DeduplicationStore $store,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        // Only check idempotency for messages received from transport
        if ($envelope->last(ReceivedStamp::class) === null) {
            return $stack->next()
                ->handle($envelope, $stack);
        }

        $messageIdStamp = $envelope->last(MessageIdStamp::class);
        if ($messageIdStamp === null) {
            // No MessageIdStamp — cannot deduplicate, but can still process
            // This means "at most once" guarantee is not provided
            return $stack->next()
                ->handle($envelope, $stack);
        }

        $messageName = $envelope->getMessage()::class;

        // Check if duplicate using store (Id is already validated at stamp construction)
        if ($this->store->isDuplicate($messageIdStamp->messageId, $messageName)) {
            // Duplicate detected — skip handler execution
            return $envelope;
        }

        // Message is new — process it
        return $stack->next()
            ->handle($envelope, $stack);
    }
}
