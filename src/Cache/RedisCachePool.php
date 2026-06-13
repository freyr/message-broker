<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Cache;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Redis;

/**
 * Redis-backed PSR-6 pool (ext-redis). A SHARED Redis removes cold-start
 * registry hits across producer/relay/consumer restarts and across processes
 * (design §7). Only scalars are cached (schema ids, schema JSON) — unserialize
 * forbids classes. Keys are namespaced so clear() never touches other data.
 */
final class RedisCachePool implements CacheItemPoolInterface
{
    /** @var array<string, CacheItemInterface> */
    private array $deferred = [];

    public function __construct(
        private readonly Redis $redis,
        private readonly string $namespace = 'mb:',
    ) {}

    public function getItem(string $key): CacheItemInterface
    {
        CacheKey::validate($key);

        $raw = $this->redis->get($this->namespace.$key);
        if (!is_string($raw)) {
            return new CacheItem($key);
        }

        $entry = @unserialize($raw, [
            'allowed_classes' => false,
        ]);
        if (!is_array($entry) || !array_key_exists('value', $entry)) {
            return new CacheItem($key);
        }

        $expiresAt = $entry['expiresAt'] ?? null;

        return CacheItem::hit($key, $entry['value'], is_int($expiresAt) ? $expiresAt : null);
    }

    /**
     * @param array<int, string> $keys
     *
     * @return array<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
        }

        return $items;
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)
            ->isHit();
    }

    public function clear(): bool
    {
        $keys = $this->redis->keys($this->namespace.'*');
        if (is_array($keys) && $keys !== []) {
            $this->redis->del($keys);
        }
        $this->deferred = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        CacheKey::validate($key);
        $this->redis->del($this->namespace.$key);

        return true;
    }

    /** @param array<int, string> $keys */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->deleteItem($key);
        }

        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        CacheKey::validate($item->getKey());

        $expiresAt = $item instanceof CacheItem ? $item->expiresAtTimestamp() : null;
        $payload = serialize([
            'value' => $item->get(),
            'expiresAt' => $expiresAt,
        ]);
        $redisKey = $this->namespace.$item->getKey();

        if ($expiresAt === null) {
            return (bool) $this->redis->set($redisKey, $payload);
        }

        $ttl = $expiresAt - time();
        if ($ttl <= 0) {
            return true; // already expired — nothing to store
        }

        return (bool) $this->redis->setex($redisKey, $ttl, $payload);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        $this->deferred[$item->getKey()] = $item;

        return true;
    }

    public function commit(): bool
    {
        $ok = true;
        foreach ($this->deferred as $item) {
            $ok = $this->save($item) && $ok;
        }
        $this->deferred = [];

        return $ok;
    }
}
