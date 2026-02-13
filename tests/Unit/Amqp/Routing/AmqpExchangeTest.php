<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Amqp\Routing;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Amqp\Routing\AmqpExchange;
use Freyr\MessageBroker\Tests\Unit\Fixtures\CommerceTestMessage;
use Freyr\MessageBroker\Tests\Unit\Fixtures\TestMessage;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for #[AmqpExchange] attribute resolution.
 */
final class AmqpExchangeTest extends TestCase
{
    public function testReturnsExchangeNameWhenAttributePresent(): void
    {
        $message = new CommerceTestMessage(orderId: Id::new(), amount: 99.99, placedAt: CarbonImmutable::now());

        $this->assertSame('commerce', AmqpExchange::fromClass($message));
    }

    public function testReturnsNullWhenAttributeAbsent(): void
    {
        $message = new TestMessage(id: Id::new(), name: 'Test', timestamp: CarbonImmutable::now());

        $this->assertNull(AmqpExchange::fromClass($message));
    }

    public function testCachingReturnsSameResult(): void
    {
        $first = new CommerceTestMessage(orderId: Id::new(), amount: 10.00, placedAt: CarbonImmutable::now());
        $second = new CommerceTestMessage(orderId: Id::new(), amount: 20.00, placedAt: CarbonImmutable::now());

        // Both instances of the same class should resolve identically (cached)
        $this->assertSame(AmqpExchange::fromClass($first), AmqpExchange::fromClass($second));
        $this->assertSame('commerce', AmqpExchange::fromClass($second));
    }
}
