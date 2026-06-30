<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Kafka;

use InvalidArgumentException;

/**
 * Transport-native relay/publish configuration: Kafka vocabulary only
 * (bootstrap brokers, topic) — AMQP and SQS relays have their own config
 * classes. The relay forces an idempotent producer with the murmur2_random
 * partitioner (acks=all is implied by idempotence), so a key always lands on
 * the same partition — matching Debezium's Java-producer default and giving
 * strict per-key FIFO.
 */
final readonly class KafkaPublishConfig
{
    public function __construct(
        public string $brokers,
        public string $topic,
    ) {
        if ($brokers === '' || $topic === '') {
            throw new InvalidArgumentException('Kafka brokers and topic must be non-empty');
        }
    }
}
