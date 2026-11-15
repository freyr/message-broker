<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Amqp;

use Freyr\MessageBroker\Consumer\DeliveryControl;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * DeliveryControl of one AMQP delivery: ack goes to the message, retry goes
 * to the wait queue publisher.
 */
final readonly class AmqpDeliveryControl implements DeliveryControl
{
    public function __construct(
        private AMQPMessage $message,
        private WaitQueuePublisher $waitQueues,
    ) {}

    public function ack(): void
    {
        $this->message->ack();
    }

    public function retry(int $delayMs, int $nextAttempt): void
    {
        $this->waitQueues->scheduleRetry($this->message, $delayMs, $nextAttempt);
    }
}
