<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures;

use Closure;
use Freyr\MessageBroker\Testing\InMemoryDeliveryStore;
use Freyr\MessageBroker\Webhook\DeliveryStore;
use Throwable;

/**
 * A delivery store over an in-memory store that throws a chosen error from
 * insert(), claim() or complete(), as a database that fails.
 * Each insert() call is reported to $log.
 */
final class FailingDeliveryStore implements DeliveryStore
{
    public ?Throwable $insertError = null;

    /** @var (Closure(string): void)|null receives 'insert' at the start of each insert() */
    public ?Closure $log = null;

    public ?Throwable $claimError = null;

    public ?Throwable $completeError = null;

    public function __construct(
        public readonly InMemoryDeliveryStore $inner = new InMemoryDeliveryStore(),
    ) {}

    public function insert(array $records): void
    {
        if ($this->log !== null) {
            ($this->log)('insert');
        }
        if ($this->insertError !== null) {
            throw $this->insertError;
        }
        $this->inner->insert($records);
    }

    public function claim(array $lanes, int $limitForEachLane, int $total, int $leaseMs): array
    {
        if ($this->claimError !== null) {
            throw $this->claimError;
        }

        return $this->inner->claim($lanes, $limitForEachLane, $total, $leaseMs);
    }

    public function complete(array $outcomes): array
    {
        if ($this->completeError !== null) {
            throw $this->completeError;
        }

        return $this->inner->complete($outcomes);
    }

    public function abandon(int $before, ?array $lanes, int $limit): int
    {
        return $this->inner->abandon($before, $lanes, $limit);
    }
}
