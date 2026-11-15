<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Transport;

use Freyr\MessageBroker\Transport\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpQueueConfig;
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

    public function testAmqpQueueConfigDefaultsToAPrefetchOf32(): void
    {
        self::assertSame(32, (new AmqpQueueConfig(queue: 'q'))->prefetch);
    }

    public function testAmqpQueueConfigAcceptsZeroPrefetchAsUnlimited(): void
    {
        self::assertSame(0, (new AmqpQueueConfig(queue: 'q', prefetch: 0))->prefetch);
    }

    public function testAmqpQueueConfigRejectsNegativePrefetch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AmqpQueueConfig(queue: 'q', prefetch: -1);
    }
}
