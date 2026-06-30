<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp;

use InvalidArgumentException;

/**
 * Transport-native consumer configuration. Each transport has its own
 * config class with its own vocabulary — no shared transport interface.
 */
final readonly class AmqpQueueConfig
{
    public function __construct(
        public string $queue,
        public int $prefetch = 32,
        // TODO slice 1: retry topology naming (wait queues, DLX), declare-on-start flag.
    ) {
        if ($queue === '') {
            throw new InvalidArgumentException('AMQP queue must be non-empty');
        }
        if ($prefetch < 0) {
            throw new InvalidArgumentException('AMQP prefetch must be >= 0 (0 = unlimited)');
        }
    }
}
