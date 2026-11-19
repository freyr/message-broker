<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Consumer;

use Freyr\MessageBroker\BrokerHeader;
use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterBuilder;
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
 *   replay target          (the header x-replay-target is present and is not
 *                           Delivery::$source → ack, event replay_skipped; the
 *                           header only, no deserialize, no transaction, no
 *                           dedup, no dispatch, no dead letter)
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
    private readonly DeadLetterBuilder $builder;

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
        $this->builder = new DeadLetterBuilder();
    }

    public function process(Delivery $delivery, DeliveryControl $control): void
    {
        // A replay goes to one consumer: a delivery whose target is another
        // queue is acked before any write, whatever its delivery count, so it
        // never becomes a deduplication row or a dead letter of this consumer.
        if (array_key_exists(BrokerHeader::REPLAY_TARGET, $delivery->headers)
            && $delivery->headers[BrokerHeader::REPLAY_TARGET] !== $delivery->source) {
            $messageId = $delivery->headers[MetadataHeader::MESSAGE_ID] ?? null;
            $messageName = $delivery->headers[MetadataHeader::MESSAGE_NAME] ?? null;
            $this->events?->record(BrokerEvents::REPLAY_SKIPPED, [
                'consumer' => $this->name,
                'source' => $delivery->source,
                'target' => $delivery->headers[BrokerHeader::REPLAY_TARGET],
                'message_id' => is_string($messageId) ? $messageId : $delivery->fallbackMessageId,
                'message_name' => is_string($messageName) ? $messageName : null,
            ]);
            $control->ack();

            return;
        }

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
            $messageId = $this->deadLetterIncoming($delivery, $incoming, $error);
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
        return $this->storeDeadLetter($this->builder->raw($delivery, $error))->messageId;
    }

    /** @return string the message id of the stored row */
    private function deadLetterIncoming(Delivery $delivery, IncomingMessage $incoming, Throwable $error): string
    {
        return $this->storeDeadLetter($this->builder->canonical($delivery, $incoming, $error))->messageId;
    }

    /** @return DeadLetter the stored dead letter: its texts are the valid, cut form of the input */
    private function storeDeadLetter(DeadLetter $deadLetter): DeadLetter
    {
        $this->deadLetters->store($deadLetter);

        $this->events?->record(BrokerEvents::DEAD_LETTERED, [
            'source' => $deadLetter->source,
            'message_id' => $deadLetter->messageId,
            'message_name' => $deadLetter->messageName,
        ]);

        return $deadLetter;
    }
}
