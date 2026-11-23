<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DeadLetter;

/**
 * The write port of a dead letter destination: the dead_letters table (each
 * {@see DeadLetterStore}) or a queue of the transport. Each consumer has one
 * sink.
 *
 * Contract:
 *  - store() returns only when the dead letter is durable at the destination;
 *    else it throws. The caller acks the delivery only after store() returns.
 *  - A call that throws can still have made the dead letter durable (a confirm
 *    after the timeout); the caller then repeats it for the same delivery.
 *  - Idempotent by id: when the destination already holds a dead letter with
 *    the same id, a store changes nothing. A queue sink cannot see its earlier
 *    messages; the import applies the rule when it stores the message in the
 *    table.
 *  - The source, message id, message name, failed_at, attempts and replayable
 *    mark reach the destination as given. A sink can cut the error message to
 *    1024 bytes and the error trace to 16384 bytes; a store that holds an open
 *    row of the same message id and source keeps the id of that row.
 */
interface DeadLetterSink
{
    public function store(DeadLetter $deadLetter): void;
}
