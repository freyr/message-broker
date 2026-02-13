<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use Freyr\MessageBroker\Contracts\MessageName;
use Freyr\MessageBroker\Contracts\MessageNameStamp;
use Freyr\MessageBroker\Contracts\OutboxMessage;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Stamps OutboxMessage envelopes with MessageNameStamp at dispatch time.
 *
 * Extracts the semantic name from the #[MessageName] attribute once and
 * attaches it as a stamp, making stamps the single source of truth for
 * message metadata downstream.
 */
final readonly class MessageNameStampMiddleware implements MiddlewareInterface
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

        if ($envelope->last(MessageNameStamp::class) === null) {
            $message = $envelope->getMessage();
            $messageName = MessageName::fromClass($message)
                ?? throw new RuntimeException(sprintf(
                    'OutboxMessage %s must have #[MessageName] attribute.',
                    $message::class,
                ));

            $envelope = $envelope->with(new MessageNameStamp($messageName));
        }

        return $stack->next()
            ->handle($envelope, $stack);
    }
}
