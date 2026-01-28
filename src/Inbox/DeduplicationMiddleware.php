<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Inbox;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

readonly class DeduplicationMiddleware implements MiddlewareInterface
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
            // Skipping deduplication for messages without MessageIdStamp
            return $stack->next()
                ->handle($envelope, $stack);
        }

        $messageId = $messageIdStamp->messageId;
        $messageName = $envelope->getMessage()::class;

        // Check if duplicate using store
        if ($this->store->isDuplicate($messageId, $messageName)) {
            // Duplicate detected - skip handler execution
            return $envelope;
        }

        // Message is new - process it
        return $stack->next()
            ->handle($envelope, $stack);
    }
}
