<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Transport;

use Freyr\MessageBroker\Transport\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpQueueConfig;
use Freyr\MessageBroker\Transport\Kafka\KafkaConsumerConfig;
use Freyr\MessageBroker\Transport\Kafka\KafkaPublishConfig;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConfigValidationTest extends TestCase
{
    public function testAmqpPublishConfigRejectsEmptyExchange(): void
    {
        new AmqpPublishConfig(exchange: 'events'); // valid, no throw
        $this->expectException(InvalidArgumentException::class);
        new AmqpPublishConfig(exchange: '');
    }

    public function testAmqpQueueConfigRejectsEmptyQueue(): void
    {
        new AmqpQueueConfig(queue: 'q'); // valid, no throw
        $this->expectException(InvalidArgumentException::class);
        new AmqpQueueConfig(queue: '');
    }

    public function testAmqpQueueConfigRejectsNegativePrefetch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AmqpQueueConfig(queue: 'q', prefetch: -1);
    }

    public function testKafkaPublishConfigRejectsEmptyBrokersOrTopic(): void
    {
        new KafkaPublishConfig(brokers: 'kafka:9092', topic: 't'); // valid
        $this->expectException(InvalidArgumentException::class);
        new KafkaPublishConfig(brokers: '', topic: 't');
    }

    public function testKafkaConsumerConfigRejectsEmptyRequiredFields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new KafkaConsumerConfig(brokers: '', topic: 't', groupId: 'g');
    }

    public function testKafkaConsumerConfigRejectsBadOffsetReset(): void
    {
        new KafkaConsumerConfig(brokers: 'kafka:9092', topic: 't', groupId: 'g'); // valid
        $this->expectException(InvalidArgumentException::class);
        new KafkaConsumerConfig(brokers: 'kafka:9092', topic: 't', groupId: 'g', autoOffsetReset: 'sideways');
    }
}
