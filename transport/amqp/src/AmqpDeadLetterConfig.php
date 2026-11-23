<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp;

use InvalidArgumentException;

/**
 * The destination of {@see AmqpDeadLetterSink}: an exchange and a routing key
 * that route to the dead letter queue, and the time that a store waits for the
 * publisher confirm.
 *
 * The exchange can be '' (the default exchange): then the routing key is the
 * name of the dead letter queue and must not be empty.
 */
final readonly class AmqpDeadLetterConfig
{
    public function __construct(
        public string $exchange,
        public string $routingKey,
        public int $confirmTimeoutSec = 5,
    ) {
        if ($exchange === '' && $routingKey === '') {
            throw new InvalidArgumentException('AMQP dead letter routing key must be non-empty with the default exchange');
        }
        if ($confirmTimeoutSec < 1) {
            throw new InvalidArgumentException("AMQP dead letter confirm timeout must be >= 1, got {$confirmTimeoutSec}");
        }
    }
}
