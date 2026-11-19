<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DeadLetter;

use Freyr\MessageBroker\Consumer\Delivery;
use Freyr\MessageBroker\Consumer\DeliveryLimitExceeded;
use Freyr\MessageBroker\Serializer\Deserializer;
use Freyr\MessageBroker\Time\EpochMillis;
use Throwable;

/**
 * Turns one message of a dead letter queue (a Delivery and an optional
 * TransportMove) into a DeadLetter and stores it:
 *
 *   a move, the message deserializes          → the canonical document, the
 *                                               source queue of the move,
 *                                               replayable
 *   a move, the message does not deserialize  → the raw bytes, the source
 *                                               queue of the move, not
 *                                               replayable
 *   no move                                   → the source of the delivery
 *                                               (the dead letter queue), not
 *                                               replayable
 *
 * The error class is DeliveryLimitExceeded for the reason 'delivery_limit',
 * else MovedByTransport. The error message names the reason, the queue and the
 * count (and the error of the deserializer, when it throws). The trace is
 * empty, because no handler ran. The attempts are the attempt of the
 * delivery, never the delivery count. The time is the time of the move, else
 * now.
 *
 * Each import gives a new id. The store keeps one open row for each message id
 * and source, so a repeat of an import changes that row.
 */
final readonly class DeadLetterImporter
{
    private const string REASON_DELIVERY_LIMIT = 'delivery_limit';

    public function __construct(
        private DeadLetterStore $store,
        private Deserializer $deserializer,
        private DeadLetterBuilder $builder,
    ) {}

    /**
     * @return DeadLetter the dead letter that was given to the store
     *
     * @throws Throwable the error of the store
     */
    public function import(Delivery $delivery, ?TransportMove $move = null): DeadLetter
    {
        if ($move === null) {
            $source = $delivery->source;
            $errorClass = MovedByTransport::class;
            $errorMessage = "Moved by the transport: reason 'unknown', queue '{$delivery->source}'";
            $failedAt = EpochMillis::now();
        } else {
            $source = $move->sourceQueue;
            $errorClass = $move->reason === self::REASON_DELIVERY_LIMIT
                ? DeliveryLimitExceeded::class
                : MovedByTransport::class;
            $errorMessage = "Moved by the transport: reason '{$move->reason}', queue '{$move->sourceQueue}', count {$move->count}";
            $failedAt = $move->movedAt ?? EpochMillis::now();
        }

        try {
            $incoming = $this->deserializer->deserialize($delivery->body, $delivery->headers);
        } catch (Throwable $error) {
            $incoming = null;
            $errorMessage .= "; the body did not deserialize: {$error->getMessage()}";
        }

        $deadLetter = $incoming === null
            ? $this->builder->rawWithError($delivery, $source, $errorClass, $errorMessage, $failedAt)
            : $this->builder->canonicalWithError(
                delivery: $delivery,
                incoming: $incoming,
                source: $source,
                errorClass: $errorClass,
                errorMessage: $errorMessage,
                failedAt: $failedAt,
                replayable: $move !== null,
            );
        $this->store->store($deadLetter);

        return $deadLetter;
    }
}
