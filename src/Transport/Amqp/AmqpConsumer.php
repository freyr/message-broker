<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp;

use Freyr\MessageBroker\Consumer\HandlerRegistry;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Consumer\PdoDeduplicationStore;
use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\ErrorHandler;
use Freyr\MessageBroker\Retry\RetryAction;
use Freyr\MessageBroker\Serializer\Deserializer;
use Freyr\MessageBroker\Serializer\MalformedMessage;
use PDO;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * Long-running AMQP worker implementing the three-stage consumer pipeline:
 *
 *   AMQPMessage (raw)                          stage 1, transport-native
 *     → Deserializer → IncomingMessage         stage 2, transport-agnostic
 *     → HandlerRegistry → userland DTO         stage 3, denormalized
 *
 * Per delivery:
 *   deserialize            (MalformedMessage → dead-letter immediately, ack;
 *                           transient failures, e.g. schema registry down,
 *                           propagate → delivery requeued, process restarts)
 *   resolve binding        (unknown name → dead-letter, ack)
 *   BEGIN pdo transaction
 *     dedup acquire        (duplicate → COMMIT, ack, skip handler)
 *     denormalize + handler($dto, $envelope)
 *   COMMIT → ack
 *   handler exception → ROLLBACK → retryPolicy->decide(attempt) →
 *     retry  : republish to a TTL wait queue that dead-letters back to the
 *              work queue (transport-native delay), x-attempt + 1, ack
 *     dlq    : dead_letters row, ack
 *     discard: ack
 */
final class AmqpConsumer
{
    private const string ATTEMPT_HEADER = 'x-attempt';

    private bool $shouldStop = false;

    private int $handled = 0;

    /** @var array<int, true> wait queues already declared, keyed by delay ms */
    private array $declaredWaitQueues = [];

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
        private readonly ?ErrorHandler $errorHandler = null,
    ) {}

    /**
     * Blocks until stopped (SIGTERM/SIGINT via pcntl), the optional message
     * limit is reached, or — when an idle timeout is set — the queue stays
     * silent for that long. Limit and timeout exist for tests and batch-style
     * operation; production workers run with neither.
     */
    public function run(?int $messageLimit = null, ?int $idleTimeoutSec = null): void
    {
        $this->registerSignalHandlers();
        $this->channel->basic_qos(0, $this->queue->prefetch, false);
        $consumerTag = $this->channel->basic_consume(queue: $this->queue->queue, callback: $this->handle(...));

        while (!$this->shouldStop && $this->channel->is_consuming()) {
            if ($messageLimit !== null && $this->handled >= $messageLimit) {
                break;
            }

            try {
                $this->channel->wait(timeout: $idleTimeoutSec ?? 0);
            } catch (AMQPTimeoutException) {
                break; // queue stayed silent for the whole idle window
            }
        }

        $this->channel->basic_cancel($consumerTag);
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    private function handle(AMQPMessage $delivery): void
    {
        /** @var array<string, mixed> $properties */
        $properties = $delivery->get_properties();
        $attempt = $this->attemptFrom($properties);

        try {
            $incoming = $this->deserializer->deserialize(
                $delivery->getBody(),
                $this->applicationHeaders($properties),
            );
        } catch (MalformedMessage $error) {
            // Malformed never improves: no retry, straight to the DLQ.
            // Anything else (e.g. RegistryUnavailable) propagates: the
            // delivery stays unacked and is redelivered — a transient
            // dependency failure must never dead-letter valid messages.
            $this->deadLetterRaw($delivery, $properties, $error, $attempt);
            $this->acknowledge($delivery);

            return;
        }

        $binding = $this->handlers->bindingFor($incoming->messageName);
        if ($binding === null) {
            $this->deadLetterIncoming($incoming, new \RuntimeException(
                "No handler bound for message '{$incoming->messageName}'",
            ), $attempt);
            $this->acknowledge($delivery);

            return;
        }

        try {
            $this->pdo->beginTransaction();

            if (!$this->deduplication->acquire($incoming, $this->name)) {
                $this->pdo->commit();
                $this->acknowledge($delivery);

                return;
            }

            $dto = $this->handlers->denormalize($incoming, $binding);
            ($binding->handler)($dto, $incoming);

            $this->pdo->commit();
            $this->acknowledge($delivery);
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            $this->afterFailure($delivery, $incoming, $error, $attempt);
        }
    }

    private function afterFailure(
        AMQPMessage $delivery,
        IncomingMessage $incoming,
        Throwable $error,
        int $attempt,
    ): void {
        $decision = $this->retryPolicy->decide($attempt, $error);

        match ($decision->action) {
            RetryAction::Retry => $this->republishToWaitQueue($delivery, $attempt + 1, $decision->delayMs),
            RetryAction::DeadLetter => $this->deadLetterIncoming($incoming, $error, $attempt),
            RetryAction::Discard => null,
        };

        $this->errorHandler?->handle($error, [
            'queue' => $this->queue->queue,
            'message_id' => $incoming->messageId,
            'message_name' => $incoming->messageName,
            'attempt' => $attempt,
            'action' => $decision->action->name,
        ]);

        $this->acknowledge($delivery);
    }

    /**
     * Transport-native delay: a durable per-delay wait queue with
     * x-message-ttl that dead-letters back to the work queue through the
     * default exchange. Declared lazily, once per delay.
     */
    private function republishToWaitQueue(AMQPMessage $delivery, int $nextAttempt, int $delayMs): void
    {
        if (!isset($this->declaredWaitQueues[$delayMs])) {
            $this->channel->queue_declare(
                $this->waitQueue($delayMs),
                false,
                true,
                false,
                false,
                false,
                new AMQPTable([
                    'x-message-ttl' => $delayMs,
                    'x-dead-letter-exchange' => '',
                    'x-dead-letter-routing-key' => $this->queue->queue,
                ]),
            );
            $this->declaredWaitQueues[$delayMs] = true;
        }

        /** @var array<string, mixed> $properties */
        $properties = $delivery->get_properties();
        $properties['application_headers'] = new AMQPTable(
            array_merge($this->applicationHeaders($properties), [
                self::ATTEMPT_HEADER => $nextAttempt,
            ]),
        );

        $this->channel->basic_publish(
            new AMQPMessage($delivery->getBody(), $properties),
            '',
            $this->waitQueue($delayMs),
        );
    }

    /** @param array<string, mixed> $properties */
    private function deadLetterRaw(
        AMQPMessage $delivery,
        array $properties,
        Throwable $error,
        int $attempt,
    ): void {
        $messageId = $properties['message_id'] ?? null;

        $this->storeDeadLetter(
            messageId: is_string($messageId) ? $messageId : 'unknown',
            messageName: 'unknown',
            body: $delivery->getBody(),
            headers: $this->applicationHeaders($properties),
            error: $error,
            attempt: $attempt,
        );
    }

    private function deadLetterIncoming(IncomingMessage $incoming, Throwable $error, int $attempt): void
    {
        // Store the reconstructed canonical document, not the wire bytes:
        // readable in dlq:show and replayable through the outbox regardless
        // of the queue's wire format (JSON or Avro). Raw bytes are kept only
        // for stage-1 failures (deadLetterRaw), which never replay anyway.
        $document = json_encode([
            'metadata' => [
                'message_name' => $incoming->messageName,
                'message_id' => $incoming->messageId,
                'created_at' => $incoming->createdAt,
            ],
            'payload' => $incoming->payload,
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

        $this->storeDeadLetter(
            messageId: $incoming->messageId,
            messageName: $incoming->messageName,
            body: $document,
            headers: $incoming->headers,
            error: $error,
            attempt: $attempt,
        );
    }

    /** @param array<string, mixed> $headers */
    private function storeDeadLetter(
        string $messageId,
        string $messageName,
        string $body,
        array $headers,
        Throwable $error,
        int $attempt,
    ): void {
        $this->deadLetters->store(DeadLetter::fromFailure(
            source: $this->queue->queue,
            messageId: $messageId,
            messageName: $messageName,
            body: $body,
            headers: $headers,
            error: $error,
            attempts: $attempt,
        ));
    }

    private function acknowledge(AMQPMessage $delivery): void
    {
        $delivery->ack();
        ++$this->handled;
    }

    /** @param array<string, mixed> $properties */
    private function attemptFrom(array $properties): int
    {
        $headers = $this->applicationHeaders($properties);
        $attempt = $headers[self::ATTEMPT_HEADER] ?? 1;

        return is_int($attempt) ? max(1, $attempt) : 1;
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    private function applicationHeaders(array $properties): array
    {
        $headers = $properties['application_headers'] ?? null;

        /** @var array<string, mixed> */
        return $headers instanceof AMQPTable ? $headers->getNativeData() : [];
    }

    private function waitQueue(int $delayMs): string
    {
        return "{$this->queue->queue}.wait.{$delayMs}";
    }

    private function registerSignalHandlers(): void
    {
        if (!extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->stop());
        pcntl_signal(SIGINT, fn () => $this->stop());
    }
}
