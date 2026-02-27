<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use Freyr\MessageBroker\Contracts\OutboxMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Validates that OutboxMessage envelopes carry a PartitionKeyStamp at dispatch time.
 *
 * This is a safety net for ordered outbox delivery: every OutboxMessage must
 * declare its partition key so the transport can enforce per-partition FIFO ordering.
 */
final readonly class PartitionKeyStampMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$envelope->getMessage() instanceof OutboxMessage) {
            return $stack->next()
                ->handle($envelope, $stack);
        }

        if ($envelope->last(ReceivedStamp::class) !== null) {
            return $stack->next()
                ->handle($envelope, $stack);
        }

        if ($envelope->last(PartitionKeyStamp::class) === null) {
            throw new \LogicException(sprintf(
                'OutboxMessage "%s" must have a PartitionKeyStamp. Dispatch with: $bus->dispatch($event, [new PartitionKeyStamp($key)])',
                $envelope->getMessage()::class,
            ));
        }

        return $stack->next()
            ->handle($envelope, $stack);
    }
}
