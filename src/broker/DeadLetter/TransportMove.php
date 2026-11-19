<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DeadLetter;

/**
 * The data of a broker move: the transport moved a message from a queue to a
 * dead letter queue by itself (for example at its own delivery limit), with no
 * handler error. It holds no term of one transport.
 */
final readonly class TransportMove
{
    /**
     * @param string $sourceQueue the queue that the transport moved the message from
     * @param string $reason the reason of the transport, for example 'delivery_limit' or 'rejected'
     * @param int $count the number of moves from that queue for that reason
     * @param int|null $movedAt the time of the move in epoch milliseconds, when the transport gives it
     */
    public function __construct(
        public string $sourceQueue,
        public string $reason,
        public int $count,
        public ?int $movedAt = null,
    ) {}
}
