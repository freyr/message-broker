<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Outbox;

use Freyr\MessageBroker\Outbox\PartitionKeyStamp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Stamp\StampInterface;

#[CoversClass(PartitionKeyStamp::class)]
final class PartitionKeyStampTest extends TestCase
{
    public function testImplementsStampInterface(): void
    {
        $stamp = new PartitionKeyStamp('order-123');

        $this->assertInstanceOf(StampInterface::class, $stamp);
    }

    public function testStoresPartitionKey(): void
    {
        $stamp = new PartitionKeyStamp('order-abc');

        $this->assertSame('order-abc', $stamp->partitionKey);
    }

    public function testAcceptsEmptyString(): void
    {
        $stamp = new PartitionKeyStamp('');

        $this->assertSame('', $stamp->partitionKey);
    }
}
