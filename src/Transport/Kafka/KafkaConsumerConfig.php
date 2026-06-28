<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Kafka;

/**
 * Transport-native consumer configuration. Kafka vocabulary only. Manual
 * offset commit is fixed (enable.auto.commit=false): the consumer commits the
 * offset only AFTER the dedup/dispatch DB transaction commits, so a crash in
 * the gap yields redelivery (absorbed by dedup) — at-least-once + dedup =
 * exactly-once processing.
 */
final readonly class KafkaConsumerConfig
{
    public function __construct(
        public string $brokers,
        public string $topic,
        public string $groupId,
        public string $autoOffsetReset = 'earliest',
    ) {}
}
