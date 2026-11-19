<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures;

use Freyr\MessageBroker\Testing\InMemoryDeliveryStore;
use Freyr\MessageBroker\Webhook\DeliveryStore;
use Throwable;

/**
 * A delivery store over an in-memory store that throws a chosen error from
 * claim() or complete(), as a database that fails, or whose complete()
 * changes nothing, as a store whose lease check skips each row.
 */
final class FailingDeliveryStore implements DeliveryStore
{
    public ?Throwable $claimError = null;

    public ?Throwable $completeError = null;

    public bool $changeNothing = false;

    public function __construct(
        public readonly InMemoryDeliveryStore $inner = new InMemoryDeliveryStore(),
    ) {}

    public function insert(array $records): void
    {
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
        if ($this->changeNothing) {
            return [];
        }

        return $this->inner->complete($outcomes);
    }

    public function claimExpired(int $before, int $limit, int $leaseMs): array
    {
        return $this->inner->claimExpired($before, $limit, $leaseMs);
    }
}
