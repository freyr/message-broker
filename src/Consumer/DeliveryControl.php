<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Consumer;

/**
 * The outcomes that the processor drives on the transport, for ONE delivery.
 */
interface DeliveryControl
{
    /** This delivery is done. */
    public function ack(): void;

    /**
     * Schedule a delayed redelivery that carries $nextAttempt. Return only
     * after the transport confirmed it; throw when the transport refuses or
     * does not confirm. It does not ack: the processor calls ack() afterwards.
     */
    public function retry(int $delayMs, int $nextAttempt): void;
}
