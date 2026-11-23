<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures;

use Closure;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Throwable;

final class RecordingOutboxStore implements OutboxStore
{
    /** @var list<OutboxRecord> */
    public array $inserted = [];

    /** When set, insert() reports the call and throws it, and records nothing. */
    public ?Throwable $insertFails = null;

    /** @param (Closure(string): void)|null $log */
    public function __construct(
        private readonly ?Closure $log = null,
    ) {}

    public function insert(OutboxRecord $record): void
    {
        if ($this->log !== null) {
            ($this->log)('insert');
        }
        if ($this->insertFails !== null) {
            throw $this->insertFails;
        }
        $this->inserted[] = $record;
    }

    public function drainClaimed(string $lane, int $limit, callable $publish): int
    {
        return 0;
    }

    public function abandon(string $id): int
    {
        return 0;
    }

    public function failing(string $lane, int $minAttempts, int $limit): array
    {
        return [];
    }
}
