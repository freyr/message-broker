<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Consumer;

use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\ErrorHandler;
use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Retry\RetryAction;
use Freyr\MessageBroker\Retry\RetryPolicy;
use Freyr\MessageBroker\Serializer\Deserializer;
use Freyr\MessageBroker\Serializer\MalformedMessage;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use Freyr\MessageBroker\Storage\Transaction;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The transport-neutral exactly-once algorithm for one delivery:
 *
 *   delivery limit         (the delivery count of the transport >= maxDeliveries
 *                           → dead-letter with DeliveryLimitExceeded, ack; no
 *                           transaction, no dedup, no dispatch)
 *   deserialize           (MalformedMessage → dead-letter immediately, ack;
 *                           transient failures, e.g. a schema that is not
 *                           known yet, propagate → no ack, the delivery is
 *                           redelivered, the process restarts)
 *   BEGIN transaction
 *     dedup acquire        (duplicate → COMMIT, ack, skip dispatch)
 *     dispatcher->dispatch($incoming)
 *   COMMIT → ack
 *   database failure (BEGIN fails, or ROLLBACK fails on a dead connection)
 *     → the error propagates; no ack, no attempt used
 *   dispatch exception → ROLLBACK → retryPolicy->decide(attempt) →
 *     retry  : control->retry() (delayed redelivery, confirmed), ack
 *     dlq    : dead_letters row, ack
 *     discard: ack
 *
 * The transport reaches the outcome through the DeliveryControl of the
 * delivery. Every path that returns normally has acked the delivery.
 */
final class DeliveryProcessor
{
    /**
     * @param int|null $maxDeliveries the delivery limit: a delivery whose count of earlier deliveries that
     *                                ended with no ack (Delivery::$deliveryCount) is at or above it becomes a
     *                                dead letter with no dispatch; null turns the limit off. A transport that
     *                                gives no count has no limit.
     * @throws InvalidArgumentException when $maxDeliveries is below 1
     */
    public function __construct(
        private readonly Deserializer $deserializer,
        private readonly MessageDispatcher $dispatcher,
        private readonly Transaction $transaction,
        private readonly DeduplicationStore $deduplication,
        private readonly RetryPolicy $retryPolicy,
        private readonly DeadLetterStore $deadLetters,
        private readonly string $name = 'default', // dedup scope
        private readonly ?ErrorHandler $errorHandler = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?BrokerEvents $events = null,
        private readonly ?int $maxDeliveries = 5,
    ) {
        if ($maxDeliveries !== null && $maxDeliveries < 1) {
            throw new InvalidArgumentException("maxDeliveries must be at least 1 or null, got {$maxDeliveries}");
        }
    }

    public function process(Delivery $delivery, DeliveryControl $control): void
    {
        $attempt = $delivery->attempt;
        $deliveryCount = $delivery->deliveryCount;

        if ($this->maxDeliveries !== null && $deliveryCount !== null && $deliveryCount >= $this->maxDeliveries) {
            $this->deadLetterOverLimit($delivery, $deliveryCount, $this->maxDeliveries);
            $control->ack();

            return;
        }

        try {
            $incoming = $this->deserializer->deserialize($delivery->body, $delivery->headers);
        } catch (MalformedMessage $error) {
            // Malformed never improves: no retry, straight to the DLQ.
            // Anything else (e.g. SchemaNotFound, an unmapped reader schema)
            // propagates: the delivery stays unacked and is redelivered — a
            // transient dependency failure must never dead-letter valid messages.
            $this->deadLetterRaw($delivery, $error);
            $control->ack();

            return;
        } catch (Throwable $error) {
            // Observe-then-rethrow: a transient dependency failure (e.g. the
            // database briefly unreachable) must surface to operators before
            // the process exits and the delivery requeues.
            $context = [
                'queue' => $delivery->source,
                'stage' => 'deserialize',
                'message_id' => $delivery->fallbackMessageId ?? 'unknown',
                'attempt' => $attempt,
            ];
            $this->logger->error('Consumer deserialize failed (transient); requeueing', [
                'exception' => $error,
            ] + $context);
            $this->errorHandler?->handle($error, $context);

            throw $error;
        }

        // A failure to begin (the database is down) propagates: the process
        // stops and the unacked delivery is redelivered, with no retry attempt.
        try {
            $this->transaction->begin();
        } catch (Throwable $error) {
            $this->reportInfrastructureFailure($delivery, $incoming, $error);

            throw $error;
        }

        try {
            if (!$this->deduplication->acquire($incoming, $this->name)) {
                $this->transaction->commit();
                $this->events?->record(BrokerEvents::DEDUPLICATED, [
                    'consumer' => $this->name,
                    'message_id' => $incoming->messageId,
                    'message_name' => $incoming->messageName,
                ]);
                $control->ack();

                return;
            }

            $this->dispatcher->dispatch($incoming);

            $this->transaction->commit();
            $this->events?->record(BrokerEvents::DISPATCHED, [
                'consumer' => $this->name,
                'message_id' => $incoming->messageId,
                'message_name' => $incoming->messageName,
            ]);
            $control->ack();
        } catch (Throwable $error) {
            try {
                $this->transaction->rollBack();
            } catch (Throwable) {
                // The connection is gone (or the commit already ended the
                // transaction): not a handler failure. Stop with the real
                // error; the unacked delivery is redelivered and dedup decides.
                $this->reportInfrastructureFailure($delivery, $incoming, $error);

                throw $error;
            }
            $this->afterFailure($delivery, $control, $incoming, $error);
        }
    }

    /**
     * The delivery limit: no transaction, no dedup, no dispatch. The message is
     * deserialized only to store the canonical document; when that fails for
     * any reason, the raw delivery is stored. A failed dead letter write
     * propagates before the ack.
     */
    private function deadLetterOverLimit(Delivery $delivery, int $deliveryCount, int $maxDeliveries): void
    {
        $error = DeliveryLimitExceeded::atCount($deliveryCount, $maxDeliveries);

        try {
            $incoming = $this->deserializer->deserialize($delivery->body, $delivery->headers);
        } catch (Throwable) {
            $incoming = null;
        }

        if ($incoming === null) {
            $messageId = $this->deadLetterRaw($delivery, $error);
        } else {
            $this->deadLetterIncoming($delivery, $incoming, $error);
            $messageId = $incoming->messageId;
        }

        $context = [
            'queue' => $delivery->source,
            'message_id' => $messageId,
            'delivery_count' => $deliveryCount,
            'max_deliveries' => $maxDeliveries,
            'attempt' => $delivery->attempt,
        ];
        $this->logger->warning('Consumer delivery limit reached; dead-lettered', [
            'exception' => $error,
        ] + $context);
        $this->errorHandler?->handle($error, $context);
    }

    private function reportInfrastructureFailure(Delivery $delivery, IncomingMessage $incoming, Throwable $error): void
    {
        $context = [
            'queue' => $delivery->source,
            'stage' => 'transaction',
            'message_id' => $incoming->messageId,
            'attempt' => $delivery->attempt,
        ];
        $this->logger->error('Consumer database failure; the process stops and the delivery is redelivered', [
            'exception' => $error,
        ] + $context);
        $this->errorHandler?->handle($error, $context);
    }

    private function afterFailure(
        Delivery $delivery,
        DeliveryControl $control,
        IncomingMessage $incoming,
        Throwable $error,
    ): void {
        $attempt = $delivery->attempt;
        $decision = $this->retryPolicy->decide($attempt, $error, $incoming);

        match ($decision->action) {
            RetryAction::Retry => $this->retry($delivery, $control, $incoming, $error, $decision->delayMs),
            RetryAction::DeadLetter => $this->deadLetterIncoming($delivery, $incoming, $error),
            RetryAction::Discard => $this->events?->record(BrokerEvents::DISCARDED, [
                'consumer' => $this->name,
                'source' => $delivery->source,
                'message_id' => $incoming->messageId,
                'message_name' => $incoming->messageName,
                'attempt' => $attempt,
                'error_class' => $error::class,
            ]),
        };

        $context = [
            'queue' => $delivery->source,
            'message_id' => $incoming->messageId,
            'message_name' => $incoming->messageName,
            'attempt' => $attempt,
            'action' => $decision->action->name,
        ];
        $this->logger->warning('Consumer dispatch failed', [
            'exception' => $error,
        ] + $context);
        $this->errorHandler?->handle($error, $context);

        $control->ack();
    }

    private function retry(
        Delivery $delivery,
        DeliveryControl $control,
        IncomingMessage $incoming,
        Throwable $error,
        int $delayMs,
    ): void {
        $control->retry($delayMs, $delivery->attempt + 1);

        // Only after the transport confirmed the retry: a refused retry throws above.
        $this->events?->record(BrokerEvents::RETRIED, [
            'consumer' => $this->name,
            'source' => $delivery->source,
            'message_id' => $incoming->messageId,
            'message_name' => $incoming->messageName,
            'attempt' => $delivery->attempt,
            'next_attempt' => $delivery->attempt + 1,
            'delay_ms' => $delayMs,
            'error_class' => $error::class,
        ]);
    }

    /** @return string the message id of the stored row */
    private function deadLetterRaw(Delivery $delivery, Throwable $error): string
    {
        // Best-effort triage: prefer the x-message-* envelope headers; fall back
        // to the transport message id and 'unknown'. Stage-1 raw dead
        // letters are never replayed, so this only improves dlq:show output.
        $messageId = 'unknown';
        $messageName = 'unknown';
        try {
            $meta = MetadataHeader::parse($delivery->headers);
            $messageId = $meta['message_id'];
            $messageName = $meta['message_name'];
        } catch (MalformedMessage) {
            if ($delivery->fallbackMessageId !== null) {
                $messageId = $delivery->fallbackMessageId;
            }
        }

        $this->storeDeadLetter(
            source: $delivery->source,
            messageId: $messageId,
            messageName: $messageName,
            body: $delivery->body,
            headers: $delivery->headers,
            error: $error,
            attempt: $delivery->attempt,
        );

        return $messageId;
    }

    private function deadLetterIncoming(Delivery $delivery, IncomingMessage $incoming, Throwable $error): void
    {
        // Store the reconstructed canonical document, not the wire bytes:
        // readable in dlq:show and replayable through the outbox regardless
        // of the queue's wire format. Raw bytes are kept only
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
            source: $delivery->source,
            messageId: $incoming->messageId,
            messageName: $incoming->messageName,
            body: $document,
            headers: $incoming->headers,
            error: $error,
            attempt: $delivery->attempt,
        );
    }

    /** @param array<string, mixed> $headers */
    private function storeDeadLetter(
        string $source,
        string $messageId,
        string $messageName,
        string $body,
        array $headers,
        Throwable $error,
        int $attempt,
    ): void {
        $this->deadLetters->store(DeadLetter::fromFailure(
            source: $source,
            messageId: $messageId,
            messageName: $messageName,
            body: $body,
            headers: $headers,
            error: $error,
            attempts: $attempt,
        ));

        $this->events?->record(BrokerEvents::DEAD_LETTERED, [
            'source' => $source,
            'message_id' => $messageId,
            'message_name' => $messageName,
        ]);
    }
}
