<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Cache;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;

/**
 * Filesystem-backed PSR-6 pool: one file per key, named by a hash of the key,
 * holding serialize(['value' => …, 'expiresAt' => …]). Only scalars are
 * cached (schema ids and schema JSON), so unserialize forbids classes.
 * Survives process restarts; not safe to share across hosts (use Redis).
 */
final class FileCachePool implements CacheItemPoolInterface
{
    /** @var array<string, CacheItemInterface> */
    private array $deferred = [];

    public function __construct(
        private readonly string $directory,
    ) {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            throw new RuntimeException("Cannot create cache directory '{$this->directory}'");
        }
    }

    public function getItem(string $key): CacheItemInterface
    {
        CacheKey::validate($key);

        $raw = @file_get_contents($this->path($key));
        if ($raw === false) {
            return new CacheItem($key);
        }

        $entry = @unserialize($raw, [
            'allowed_classes' => false,
        ]);
        if (!is_array($entry) || !array_key_exists('value', $entry)) {
            return new CacheItem($key);
        }

        $expiresAt = $entry['expiresAt'] ?? null;
        $expiresAt = is_int($expiresAt) ? $expiresAt : null;
        if ($expiresAt !== null && $expiresAt <= time()) {
            @unlink($this->path($key));

            return new CacheItem($key);
        }

        return CacheItem::hit($key, $entry['value'], $expiresAt);
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
        foreach (glob(rtrim($this->directory, '/').'/*.cache') ?: [] as $file) {
            @unlink($file);
        }
        $this->deferred = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        CacheKey::validate($key);
        $path = $this->path($key);

        return !is_file($path) || @unlink($path);
    }

    /** @param array<int, string> $keys */
    public function deleteItems(array $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->deleteItem($key) && $ok;
        }

        return $ok;
    }

    public function save(CacheItemInterface $item): bool
    {
        CacheKey::validate($item->getKey());

        $payload = serialize([
            'value' => $item->get(),
            'expiresAt' => $item instanceof CacheItem ? $item->expiresAtTimestamp() : null,
        ]);

        $path = $this->path($item->getKey());
        $tmp = $path.'.'.uniqid('', true).'.tmp';
        if (@file_put_contents($tmp, $payload) === false) {
            return false;
        }

        return @rename($tmp, $path);
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

    private function path(string $key): string
    {
        return rtrim($this->directory, '/').'/'.hash('xxh128', $key).'.cache';
    }
}
