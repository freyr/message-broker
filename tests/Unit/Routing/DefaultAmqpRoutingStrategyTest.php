<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Routing;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Outbox\Routing\DefaultAmqpRoutingStrategy;
use Freyr\MessageBroker\Tests\Unit\Fixtures\CommerceTestMessage;
use Freyr\MessageBroker\Tests\Unit\Fixtures\TestMessage;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for DefaultAmqpRoutingStrategy.
 *
 * Tests sender name resolution, routing key derivation, and header generation.
 */
final class DefaultAmqpRoutingStrategyTest extends TestCase
{
    public function testDefaultSenderNameWhenNoAmqpExchangeAttribute(): void
    {
        $strategy = new DefaultAmqpRoutingStrategy();
        $message = new TestMessage(id: Id::new(), name: 'Test', timestamp: CarbonImmutable::now());

        $this->assertEquals('amqp', $strategy->getSenderName($message));
    }

    public function testCustomSenderNameFromAmqpExchangeAttribute(): void
    {
        $strategy = new DefaultAmqpRoutingStrategy();
        $message = new CommerceTestMessage(orderId: Id::new(), amount: 99.99, placedAt: CarbonImmutable::now());

        $this->assertEquals('commerce', $strategy->getSenderName($message));
    }

    public function testCustomDefaultSenderNameViaConstructor(): void
    {
        $strategy = new DefaultAmqpRoutingStrategy(defaultSenderName: 'events');
        $message = new TestMessage(id: Id::new(), name: 'Test', timestamp: CarbonImmutable::now());

        $this->assertEquals('events', $strategy->getSenderName($message));
    }

    public function testAmqpExchangeAttributeOverridesCustomDefault(): void
    {
        $strategy = new DefaultAmqpRoutingStrategy(defaultSenderName: 'events');
        $message = new CommerceTestMessage(orderId: Id::new(), amount: 50.00, placedAt: CarbonImmutable::now());

        $this->assertEquals('commerce', $strategy->getSenderName($message));
    }

    public function testRoutingKeyDefaultsToMessageName(): void
    {
        $strategy = new DefaultAmqpRoutingStrategy();
        $message = new TestMessage(id: Id::new(), name: 'Test', timestamp: CarbonImmutable::now());

        $this->assertEquals('order.placed', $strategy->getRoutingKey($message, 'order.placed'));
    }

    public function testHeadersContainMessageName(): void
    {
        $strategy = new DefaultAmqpRoutingStrategy();

        $headers = $strategy->getHeaders('order.placed');

        $this->assertEquals(['x-message-name' => 'order.placed'], $headers);
    }
}
