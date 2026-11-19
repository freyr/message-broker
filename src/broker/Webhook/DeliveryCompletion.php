<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

use Freyr\MessageBroker\DeadLetter\DeadLetter;

/**
 * The outcome of one claimed delivery, as {@see DeliveryStore::complete()}
 * takes it. `attempts` is the new value of the attempts column:
 *
 *  - delivered: one more attempt, and the final state delivered;
 *  - retry: one more attempt, and available_at = $availableAt;
 *  - dead: the attempt count of the dead letter, the final state dead, and
 *    the dead letter (source `webhook:<lane>`). A dead letter after a result
 *    counts that request; a dead letter of an expired row counts none.
 */
final readonly class DeliveryCompletion
{
    private function __construct(
        public ClaimedDelivery $claimed,
        public DeliveryCompletionKind $kind,
        public int $attempts,
        public ?int $availableAt = null,
        public ?DeadLetter $deadLetter = null,
    ) {}

    public static function delivered(ClaimedDelivery $claimed): self
    {
        return new self($claimed, DeliveryCompletionKind::Delivered, $claimed->delivery->attempts + 1);
    }

    /** @param int $availableAt the time of the next claim, epoch milliseconds */
    public static function retry(ClaimedDelivery $claimed, int $availableAt): self
    {
        return new self($claimed, DeliveryCompletionKind::Retry, $claimed->delivery->attempts + 1, $availableAt);
    }

    public static function dead(ClaimedDelivery $claimed, DeadLetter $deadLetter): self
    {
        return new self($claimed, DeliveryCompletionKind::Dead, $deadLetter->attempts, deadLetter: $deadLetter);
    }
}
