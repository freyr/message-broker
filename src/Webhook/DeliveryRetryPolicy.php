<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

/**
 * Decides, after a request whose result is not delivered, between a retry at
 * a time and a dead letter. The core default is
 * {@see DefaultDeliveryRetryPolicy}; userland can replace it.
 *
 * {@see \Freyr\MessageBroker\Retry\RetryPolicy} does not serve this decision:
 * it takes a Throwable and an IncomingMessage of the consumer.
 */
interface DeliveryRetryPolicy
{
    /**
     * @param int $attempt the number of the attempt that just got the result, from 1
     *                     (the attempts of the row plus one)
     * @param DeliveryResult $result a failed or refused result; the loop never passes a delivered one
     */
    public function decide(int $attempt, DeliveryResult $result, DeliveryRecord $delivery): DeliveryDecision;
}
