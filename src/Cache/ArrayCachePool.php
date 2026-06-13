<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Cache;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * In-memory PSR-6 pool — the default for HttpSchemaRegistry. Per-process,
 * never shared; good for a single long-running relay/consumer/producer.
 * Use FileCachePool or RedisCachePool to survive restarts / share processes.
 */
final class ArrayCachePool implements CacheItemPoolInterface
{
    /** @var array<string, array{value: mixed, expiresAt: int|null}> */
    private array $store = [];

    /** @var array<string, CacheItemInterface> */
    private array $deferred = [];

    public function getItem(string $key): CacheItemInterface
    {
        CacheKey::validate($key);

        $entry = $this->store[$key] ?? null;
        if ($entry === null) {
            return new CacheItem($key);
        }

        if ($entry['expiresAt'] !== null && $entry['expiresAt'] <= time()) {
            unset($this->store[$key]);

            return new CacheItem($key);
        }

        return CacheItem::hit($key, $entry['value'], $entry['expiresAt']);
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
        $this->store = [];
        $this->deferred = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        CacheKey::validate($key);
        unset($this->store[$key]);

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
        $this->store[$item->getKey()] = [
            'value' => $item->get(),
            'expiresAt' => $item instanceof CacheItem ? $item->expiresAtTimestamp() : null,
        ];

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        $this->deferred[$item->getKey()] = $item;

        return true;
    }

    public function commit(): bool
    {
        foreach ($this->deferred as $item) {
            $this->save($item);
        }
        $this->deferred = [];

        return true;
    }
}
