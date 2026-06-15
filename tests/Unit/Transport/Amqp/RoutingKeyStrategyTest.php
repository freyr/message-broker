<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Transport\Amqp;

use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Transport\Amqp\RoutingKeyStrategy;
use PHPUnit\Framework\TestCase;

final class RoutingKeyStrategyTest extends TestCase
{
    public function testMessageNameResolvesToTheMessageName(): void
    {
        self::assertSame('order.placed', RoutingKeyStrategy::MessageName->resolve($this->record()));
    }

    public function testMessageKeyResolvesToTheRecordKey(): void
    {
        self::assertSame('o-42', RoutingKeyStrategy::MessageKey->resolve($this->record()));
    }

    private function record(): OutboxRecord
    {
        return new OutboxRecord(
            id: 'm-1',
            lane: 'default',
            key: 'o-42',
            metadata: [
                'message_name' => 'order.placed',
                'message_id' => 'm-1',
                'created_at' => 1_700_000_000_000,
            ],
            body: '{}',
            headers: [],
            createdAt: 1_700_000_000_000,
        );
    }
}
