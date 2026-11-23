<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DeadLetter;

use Freyr\MessageBroker\BrokerHeader;
use Freyr\MessageBroker\Consumer\Delivery;
use Freyr\MessageBroker\Consumer\DeliveryLimitExceeded;
use Freyr\MessageBroker\Serializer\Deserializer;
use Freyr\MessageBroker\Time\EpochMillis;
use Throwable;

/**
 * Turns one message of a dead letter queue (a Delivery and an optional
 * TransportMove) into a DeadLetter and stores it:
 *
 *   a valid header x-dead-letter              → the dead letter of the header
 *                                               (a transport sink wrote it):
 *                                               its id and each field, the
 *                                               message body, the headers
 *                                               without x-dead-letter; it has
 *                                               priority over a move
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
 * A header x-dead-letter that does not decode ({@see DeadLetterHeader::decode()}
 * returns null) counts as absent, and the error message says so.
 *
 * With a valid header, the id comes from the header, and the store, idempotent
 * by id, changes nothing on a repeat (also when the row is replayed). Else
 * each import gives a new id; the store keeps one open row for each message
 * id and source, so a repeat of an import changes that row.
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
        $header = DeadLetterHeader::decode($delivery->headers[BrokerHeader::DEAD_LETTER] ?? null);
        if ($header !== null) {
            $headers = $delivery->headers;
            unset($headers[BrokerHeader::DEAD_LETTER]);
            $deadLetter = new DeadLetter(
                id: $header['id'],
                source: $header['source'],
                messageId: $header['message_id'],
                messageName: $header['message_name'],
                body: $delivery->body,
                headers: $headers,
                errorClass: $header['error_class'],
                errorMessage: $header['error_message'],
                errorTrace: $header['error_trace'],
                attempts: $header['attempts'],
                failedAt: $header['failed_at'],
                replayable: $header['replayable'],
            );
            $this->store->store($deadLetter);

            return $deadLetter;
        }

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
        if (array_key_exists(BrokerHeader::DEAD_LETTER, $delivery->headers)) {
            $errorMessage .= '; x-dead-letter header did not decode';
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
