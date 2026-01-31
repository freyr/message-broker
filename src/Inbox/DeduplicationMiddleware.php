<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Inbox;

use Freyr\Identity\Id;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

readonly class DeduplicationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private DeduplicationStore $store,
        private ?LoggerInterface $logger = null,
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
            // No MessageIdStamp - cannot deduplicate, but can still process
            // This means "at most once" guarantee is not provided
            return $stack->next()
                ->handle($envelope, $stack);
        }

        $messageId = $messageIdStamp->messageId;

        // Validate UUID format using Freyr\Identity\Id (throws exception if invalid)
        try {
            Id::fromString($messageId);
        } catch (\InvalidArgumentException $e) {
            // Log full details internally for debugging
            $this->logger?->warning('Invalid UUID in MessageIdStamp', [
                'message_id' => $messageId,
                'message_class' => $envelope->getMessage()::class,
                'error' => $e->getMessage(),
            ]);

            // Generic error message to external systems (prevents information disclosure)
            throw new \InvalidArgumentException('MessageIdStamp contains invalid UUID format', 0, $e);
        }

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
