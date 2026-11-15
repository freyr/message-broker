<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures;

use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\OutboxStore;

final class RecordingOutboxStore implements OutboxStore
{
    /** @var list<OutboxRecord> */
    public array $inserted = [];

    public function insert(OutboxRecord $record): void
    {
        $this->inserted[] = $record;
    }

    public function drainClaimed(string $lane, int $limit, callable $publish): int
    {
        return 0;
    }
}
