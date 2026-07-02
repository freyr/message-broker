<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp;

use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Consumer\MessageDispatcher;
use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\ErrorHandler;
use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Retry\RetryAction;
use Freyr\MessageBroker\Serializer\Deserializer;
use Freyr\MessageBroker\Serializer\MalformedMessage;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use Freyr\MessageBroker\Transport\DeduplicationStore;
use PDO;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Long-running AMQP worker implementing the three-stage consumer pipeline:
 *
 *   AMQPMessage (raw)                          stage 1, transport-native
 *     → Deserializer → IncomingMessage         stage 2, transport-agnostic
 *     → MessageDispatcher::dispatch($incoming)  hand-off to the app's handling
 *                                               layer (denormalize + routing
 *                                               live in a separate component)
 *
 * Per delivery:
 *   deserialize            (MalformedMessage → dead-letter immediately, ack;
 *                           transient failures, e.g. schema registry down,
 *                           propagate → delivery requeued, process restarts)
 *   BEGIN pdo transaction
 *     dedup acquire        (duplicate → COMMIT, ack, skip dispatch)
 *     dispatcher->dispatch($incoming)
 *   COMMIT → ack
 *   dispatch exception → ROLLBACK → retryPolicy->decide(attempt) →
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
        private readonly MessageDispatcher $dispatcher,
        private readonly PDO $pdo,
        private readonly DeduplicationStore $deduplication,
        private readonly AmqpRetryPolicy $retryPolicy,
        private readonly DeadLetterStore $deadLetters,
        private readonly string $name = 'default', // dedup scope
        private readonly ?ErrorHandler $errorHandler = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?BrokerEvents $events = null,
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

        // Transient deserialize failures intentionally propagate past this
        // cancel; the unacked delivery is requeued on connection close —
        // do not "fix" with a finally.
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
        } catch (Throwable $error) {
            // Observe-then-rethrow: a transient dependency failure (e.g.
            // schema registry down) must surface to operators before the
            // process exits and the delivery requeues.
            $messageId = $properties['message_id'] ?? null;
            $context = [
                'queue' => $this->queue->queue,
                'stage' => 'deserialize',
                'message_id' => is_string($messageId) ? $messageId : 'unknown',
                'attempt' => $attempt,
            ];
            $this->logger->error('Consumer deserialize failed (transient); requeueing', [
                'exception' => $error,
            ] + $context);
            $this->errorHandler?->handle($error, $context);

            throw $error;
        }

        try {
            $this->pdo->beginTransaction();

            if (!$this->deduplication->acquire($incoming, $this->name)) {
                $this->pdo->commit();
                $this->events?->record(BrokerEvents::DEDUPLICATED, [
                    'consumer' => $this->name,
                    'message_id' => $incoming->messageId,
                    'message_name' => $incoming->messageName,
                ]);
                $this->acknowledge($delivery);

                return;
            }

            $this->dispatcher->dispatch($incoming);

            $this->pdo->commit();
            $this->events?->record(BrokerEvents::DISPATCHED, [
                'consumer' => $this->name,
                'message_id' => $incoming->messageId,
                'message_name' => $incoming->messageName,
            ]);
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

        $context = [
            'queue' => $this->queue->queue,
            'message_id' => $incoming->messageId,
            'message_name' => $incoming->messageName,
            'attempt' => $attempt,
            'action' => $decision->action->name,
        ];
        $this->logger->warning('Consumer dispatch failed', [
            'exception' => $error,
        ] + $context);
        $this->errorHandler?->handle($error, $context);

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
        $appHeaders = $this->applicationHeaders($properties);

        // Best-effort triage: prefer the x-message-* envelope headers; fall back
        // to the AMQP message_id property and 'unknown'. Stage-1 raw dead
        // letters are never replayed, so this only improves dlq:show output.
        $messageId = 'unknown';
        $messageName = 'unknown';
        try {
            $meta = MetadataHeader::parse($appHeaders);
            $messageId = $meta['message_id'];
            $messageName = $meta['message_name'];
        } catch (MalformedMessage) {
            $propId = $properties['message_id'] ?? null;
            if (is_string($propId)) {
                $messageId = $propId;
            }
        }

        $this->storeDeadLetter(
            messageId: $messageId,
            messageName: $messageName,
            body: $delivery->getBody(),
            headers: $appHeaders,
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
            // INVALID_UTF8_SUBSTITUTE: substitution preferred over a failed
            // dead-letter write.
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

        $this->events?->record(BrokerEvents::DEAD_LETTERED, [
            'source' => $this->queue->queue,
            'message_id' => $messageId,
            'message_name' => $messageName,
        ]);
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
