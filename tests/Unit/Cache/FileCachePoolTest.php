<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Cache;

use Freyr\MessageBroker\Cache\FileCachePool;
use Freyr\MessageBroker\Cache\InvalidCacheKey;
use PHPUnit\Framework\TestCase;

final class FileCachePoolTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/mb-cache-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testMissThenSaveThenHitSurvivesANewPoolInstance(): void
    {
        new FileCachePool($this->dir)
            ->save(new FileCachePool($this->dir)->getItem('mb.subject.id.abc')->set(42));

        // A SEPARATE instance reads it back — proves on-disk persistence.
        $item = new FileCachePool($this->dir)
            ->getItem('mb.subject.id.abc');
        self::assertTrue($item->isHit());
        self::assertSame(42, $item->get());
    }

    public function testSchemaJsonStringRoundTrips(): void
    {
        $pool = new FileCachePool($this->dir);
        $json = '{"type":"record","name":"X","fields":[]}';
        $pool->save($pool->getItem('mb.schema.json.7')->set($json));

        self::assertSame($json, $pool->getItem('mb.schema.json.7')->get());
    }

    public function testExpiredItemIsAMiss(): void
    {
        $pool = new FileCachePool($this->dir);
        $pool->save($pool->getItem('k')->set('v')->expiresAfter(-1));

        self::assertFalse($pool->getItem('k')->isHit());
    }

    public function testDeleteAndClear(): void
    {
        $pool = new FileCachePool($this->dir);
        $pool->save($pool->getItem('a')->set(1));
        $pool->save($pool->getItem('b')->set(2));

        $pool->deleteItem('a');
        self::assertFalse($pool->hasItem('a'));

        $pool->clear();
        self::assertFalse($pool->hasItem('b'));
    }

    public function testReservedCharacterKeyThrows(): void
    {
        $this->expectException(InvalidCacheKey::class);

        new FileCachePool($this->dir)
            ->getItem('bad/key');
    }
}
