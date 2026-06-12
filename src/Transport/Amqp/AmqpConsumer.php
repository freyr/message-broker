<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp;

use Freyr\MessageBroker\Consumer\HandlerRegistry;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Consumer\PdoDeduplicationStore;
use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\Retry\RetryAction;
use Freyr\MessageBroker\Serializer\Deserializer;
use PDO;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

/**
 * Long-running AMQP worker implementing the three-stage consumer pipeline:
 *
 *   AMQPMessage (raw)                          stage 1, transport-native
 *     → Deserializer → IncomingMessage         stage 2, transport-agnostic
 *     → HandlerRegistry → userland DTO         stage 3, denormalized
 *
 * Per delivery:
 *   deserialize            (failure → dead-letter immediately, ack)
 *   resolve binding        (unknown name → dead-letter, ack)
 *   BEGIN pdo transaction
 *     dedup acquire        (duplicate → COMMIT, ack, skip handler)
 *     denormalize          (failure → ROLLBACK, dead-letter, ack)
 *     handler($dto, $envelope)
 *   COMMIT → ack
 *   handler exception → ROLLBACK → retryPolicy->decide() →
 *     retry  : publish to TTL wait queue (DLX back to work queue), ack
 *     dlq    : dead_letters row, ack
 *     discard: ack
 */
final class AmqpConsumer
{
    public function __construct(
        private readonly AMQPChannel $channel,
        private readonly AmqpQueueConfig $queue,
        private readonly Deserializer $deserializer,
        private readonly HandlerRegistry $handlers,
        private readonly PDO $pdo,
        private readonly PdoDeduplicationStore $deduplication,
        private readonly AmqpRetryPolicy $retryPolicy,
        private readonly PdoDeadLetterStore $deadLetters,
        private readonly string $name = 'default', // dedup scope
        // TODO slice 1: optional ErrorHandler hook for logging/metrics.
    ) {}

    public function run(): void
    {
        // TODO slice 1: declare retry topology, pcntl SIGTERM/SIGINT handling.
        $this->channel->basic_qos(0, $this->queue->prefetch, false);
        $this->channel->basic_consume(queue: $this->queue->queue, callback: $this->handle(...));

        while ($this->channel->is_consuming()) {
            $this->channel->wait();
        }
    }

    private function handle(AMQPMessage $delivery): void
    {
        try {
            $incoming = $this->deserializer->deserialize($delivery->getBody(), $delivery->get_properties());
        } catch (Throwable $error) {
            // Malformed never improves: no retry, straight to the DLQ.
            $this->deadLetterRaw($delivery, $error);
            $delivery->ack();

            return;
        }

        $binding = $this->handlers->bindingFor($incoming->messageName);
        if ($binding === null) {
            $this->deadLetterIncoming($incoming, new \RuntimeException('No handler bound'));
            $delivery->ack();

            return;
        }

        try {
            $this->pdo->beginTransaction();

            if (!$this->deduplication->acquire($incoming, $this->name)) {
                $this->pdo->commit();
                $delivery->ack();

                return;
            }

            $dto = $this->handlers->denormalize($incoming, $binding);
            ($binding->handler)($dto, $incoming);

            $this->pdo->commit();
            $delivery->ack();
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            $this->afterFailure($delivery, $incoming, $error);
        }
    }

    private function afterFailure(AMQPMessage $delivery, IncomingMessage $incoming, Throwable $error): void
    {
        $attempt = $this->attemptFrom($delivery);
        $decision = $this->retryPolicy->decide($attempt, $error);

        match ($decision->action) {
            // TODO slice 1: republish to wait queue named for $decision->delayMs
            // (x-message-ttl + DLX back to the work queue), attempt+1 header.
            RetryAction::Retry => null,
            RetryAction::DeadLetter => $this->deadLetterIncoming($incoming, $error),
            RetryAction::Discard => null,
        };

        $delivery->ack();
    }

    private function attemptFrom(AMQPMessage $delivery): int
    {
        // TODO slice 1: read attempt count from application headers.
        return 1;
    }

    private function deadLetterRaw(AMQPMessage $delivery, Throwable $error): void
    {
        // TODO slice 1: DeadLetter::fromFailure(...) → deadLetters->store().
    }

    private function deadLetterIncoming(IncomingMessage $incoming, Throwable $error): void
    {
        // TODO slice 1: DeadLetter::fromFailure(...) → deadLetters->store().
    }
}
