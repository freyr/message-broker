<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Partition key stamp for ordered outbox delivery.
 *
 * Identifies the causal group (e.g. aggregate ID) for per-partition FIFO ordering.
 * Messages with the same partition key are delivered to AMQP in insertion order.
 */
final readonly class PartitionKeyStamp implements StampInterface
{
    public function __construct(
        public string $partitionKey,
    ) {}
}
