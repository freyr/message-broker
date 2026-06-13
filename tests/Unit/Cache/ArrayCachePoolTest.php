<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Cache;

use Freyr\MessageBroker\Cache\ArrayCachePool;
use Freyr\MessageBroker\Cache\InvalidCacheKey;
use PHPUnit\Framework\TestCase;

final class ArrayCachePoolTest extends TestCase
{
    public function testMissReturnsNonHitItemWithNullValue(): void
    {
        $item = new ArrayCachePool()
            ->getItem('mb.schema.json.7');

        self::assertFalse($item->isHit());
        self::assertNull($item->get());
        self::assertSame('mb.schema.json.7', $item->getKey());
    }

    public function testSaveThenGetReturnsHit(): void
    {
        $pool = new ArrayCachePool();
        $pool->save($pool->getItem('mb.subject.id.abc')->set(42));

        $item = $pool->getItem('mb.subject.id.abc');
        self::assertTrue($item->isHit());
        self::assertSame(42, $item->get());
        self::assertTrue($pool->hasItem('mb.subject.id.abc'));
    }

    public function testExpiredItemIsAMiss(): void
    {
        $pool = new ArrayCachePool();
        $pool->save($pool->getItem('k')->set('v')->expiresAfter(-1));

        self::assertFalse($pool->getItem('k')->isHit());
    }

    public function testDeferredCommitPersists(): void
    {
        $pool = new ArrayCachePool();
        $pool->saveDeferred($pool->getItem('k')->set('v'));

        self::assertFalse($pool->getItem('k')->isHit(), 'deferred is not visible before commit');
        $pool->commit();
        self::assertTrue($pool->getItem('k')->isHit());
    }

    public function testDeleteAndClear(): void
    {
        $pool = new ArrayCachePool();
        $pool->save($pool->getItem('a')->set(1));
        $pool->save($pool->getItem('b')->set(2));

        $pool->deleteItem('a');
        self::assertFalse($pool->hasItem('a'));
        self::assertTrue($pool->hasItem('b'));

        $pool->clear();
        self::assertFalse($pool->hasItem('b'));
    }

    public function testReservedCharacterKeyThrows(): void
    {
        $this->expectException(InvalidCacheKey::class);

        new ArrayCachePool()
            ->getItem('bad:key');
    }
}
