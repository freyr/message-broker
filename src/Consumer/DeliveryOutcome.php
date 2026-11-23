<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Consumer;

/**
 * The result of one delivery that {@see DeliveryProcessor::process()} ended
 * normally: each case has acked the delivery. A delivery that throws has no
 * outcome. A worker loop reads it to count handler failures.
 */
enum DeliveryOutcome
{
    /** The handler ran and the transaction committed. */
    case Dispatched;

    /** The deduplication store knew the message: no dispatch. */
    case Deduplicated;

    /** The message could not be deserialized: a raw dead letter. */
    case Malformed;

    /** The handler failed and the retry policy scheduled a retry. */
    case Retried;

    /** The handler failed and the retry policy chose a dead letter. */
    case DeadLettered;

    /** The handler failed and the retry policy chose a discard. */
    case Discarded;

    /** The delivery count reached the delivery limit: a dead letter with no dispatch. */
    case DeliveryLimitExceeded;

    /** The replay target is a different queue: an ack with no other effect. */
    case ReplaySkipped;

    /** True only for the three results of a handler failure: Retried, DeadLettered and Discarded. */
    public function isHandlerFailure(): bool
    {
        return match ($this) {
            self::Retried, self::DeadLettered, self::Discarded => true,
            default => false,
        };
    }
}
