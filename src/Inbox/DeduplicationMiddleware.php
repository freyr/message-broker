<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Inbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Freyr\Identity\Id;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

readonly class DeduplicationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Connection $connection,
        private ?LoggerInterface $logger = null
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        // Only check idempotency for messages received from transport
        if ($envelope->last(ReceivedStamp::class) === null) {
            return $stack->next()->handle($envelope, $stack);
        }

        // Extract message_id and message_name from stamps
        $messageIdStamp = $envelope->last(MessageIdStamp::class);
        $messageNameStamp = $envelope->last(MessageNameStamp::class);

        if ($messageIdStamp === null || $messageNameStamp === null) {
            throw new \RuntimeException(
                'Message must have MessageIdStamp and MessageNameStamp for deduplication'
            );
        }

        $messageId = Id::fromString($messageIdStamp->messageId)->toBinary();
        $messageName = $messageNameStamp->messageName;

        // Try to claim this message atomically
        // This INSERT happens INSIDE the doctrine_transaction middleware's transaction
        try {
            $this->connection->insert('message_broker_deduplication', [
                'message_id' => $messageId,
                'message_name' => $messageName,  // Logical name!
                'processed_at' => date('Y-m-d H:i:s')
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Message already processed (duplicate detected by database constraint)
            $this->logger?->info('Duplicate message skipped by idempotency check', [
                'message_id' => $messageId,
                'message_name' => $messageName
            ]);

            // Skip handler execution - return without calling next middleware
            return $envelope;
        }

        // Message is new - process it
        // If handler throws exception, the transaction rolls back
        // and the INSERT above is rolled back too (message can be retried)
        return $stack->next()->handle($envelope, $stack);
    }
}