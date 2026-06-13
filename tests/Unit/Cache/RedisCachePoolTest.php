<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Cache;

use Freyr\MessageBroker\Cache\InvalidCacheKey;
use Freyr\MessageBroker\Cache\RedisCachePool;
use PHPUnit\Framework\TestCase;
use Redis;

final class RedisCachePoolTest extends TestCase
{
    private Redis $redis;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('ext-redis not loaded');
        }

        $host = getenv('REDIS_HOST') ?: 'redis';
        $this->redis = new Redis();
        try {
            $this->redis->connect($host, 6379, 1.0);
        } catch (\RedisException) {
            self::markTestSkipped("Redis not reachable at {$host}:6379");
        }
        // Isolate every test run in its own namespace, then flush it.
        $this->redis->flushDB();
    }

    private function pool(): RedisCachePool
    {
        return new RedisCachePool($this->redis, namespace: 'mbtest:');
    }

    public function testSaveThenGetReturnsHitAcrossPoolInstances(): void
    {
        $this->pool()
            ->save($this->pool()->getItem('mb.subject.id.abc')->set(42));

        $item = $this->pool()
            ->getItem('mb.subject.id.abc');
        self::assertTrue($item->isHit());
        self::assertSame(42, $item->get());
    }

    public function testSchemaJsonStringRoundTrips(): void
    {
        $pool = $this->pool();
        $json = '{"type":"record","name":"X","fields":[]}';
        $pool->save($pool->getItem('mb.schema.json.7')->set($json));

        self::assertSame($json, $pool->getItem('mb.schema.json.7')->get());
    }

    public function testDeleteAndClear(): void
    {
        $pool = $this->pool();
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

        $this->pool()
            ->getItem('bad@key');
    }
}
