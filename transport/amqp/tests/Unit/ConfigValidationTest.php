<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp\Tests\Unit;

use Freyr\MessageBroker\Transport\Amqp\AmqpDeadLetterConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpQueueConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testAmqpDeadLetterConfigKeepsItsValuesAndDefaultsToAConfirmTimeoutOf5(): void
    {
        $config = new AmqpDeadLetterConfig('dlx', 'orders_q.dlq');

        self::assertSame('dlx', $config->exchange);
        self::assertSame('orders_q.dlq', $config->routingKey);
        self::assertSame(5, $config->confirmTimeoutSec);
        self::assertSame(1, (new AmqpDeadLetterConfig('dlx', 'k', confirmTimeoutSec: 1))->confirmTimeoutSec);
    }

    public function testAmqpDeadLetterConfigAcceptsTheDefaultExchangeWithAQueueName(): void
    {
        $config = new AmqpDeadLetterConfig('', 'orders_q.dlq');

        self::assertSame('', $config->exchange);
        self::assertSame('orders_q.dlq', $config->routingKey);
    }

    public function testAmqpDeadLetterConfigAcceptsANamedExchangeWithAnEmptyRoutingKey(): void
    {
        self::assertSame('', (new AmqpDeadLetterConfig('dlx', ''))->routingKey);
    }

    public function testAmqpDeadLetterConfigRejectsTheDefaultExchangeWithAnEmptyRoutingKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('AMQP dead letter routing key must be non-empty with the default exchange');
        new AmqpDeadLetterConfig('', '');
    }

    /** @return iterable<string, array{int}> */
    public static function confirmTimeoutsBelowOne(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('confirmTimeoutsBelowOne')]
    public function testAmqpDeadLetterConfigRejectsAConfirmTimeoutBelowOne(int $timeout): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("AMQP dead letter confirm timeout must be >= 1, got {$timeout}");
        new AmqpDeadLetterConfig('dlx', 'k', confirmTimeoutSec: $timeout);
    }
}
