<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Cache;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;

final class CacheItem implements CacheItemInterface
{
    private mixed $value = null;

    private bool $isHit = false;

    /** epoch seconds; null = no expiry */
    private ?int $expiresAt = null;

    public function __construct(
        private readonly string $key,
    ) {}

    /** @internal pools build a hydrated hit through this factory */
    public static function hit(string $key, mixed $value, ?int $expiresAt): self
    {
        $item = new self($key);
        $item->value = $value;
        $item->isHit = true;
        $item->expiresAt = $expiresAt;

        return $item;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->isHit ? $this->value : null;
    }

    public function isHit(): bool
    {
        return $this->isHit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;
        $this->isHit = true;

        return $this;
    }

    public function expiresAt(?DateTimeInterface $expiration): static
    {
        $this->expiresAt = $expiration?->getTimestamp();

        return $this;
    }

    public function expiresAfter(int|DateInterval|null $time): static
    {
        if ($time === null) {
            $this->expiresAt = null;

            return $this;
        }

        $seconds = $time instanceof DateInterval
            ? new DateTimeImmutable('@0')
                ->add($time)
                ->getTimestamp()
            : $time;

        $this->expiresAt = time() + $seconds;

        return $this;
    }

    /** @internal pools read the resolved expiry to persist it */
    public function expiresAtTimestamp(): ?int
    {
        return $this->expiresAt;
    }
}
