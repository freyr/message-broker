<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures;

use Freyr\MessageBroker\Outbox\OutboxPublisher;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use RuntimeException;

/** Scripted OutboxPublisher: records each call, refuses chosen IDs, throws on chosen calls. */
final class FakeOutboxPublisher implements OutboxPublisher
{
    /** @var list<list<string>> the IDs of each publishBatch() call */
    public array $calls = [];

    /** @var list<string> IDs that the fake refuses */
    public array $refuse = [];

    /** @var list<int> numbers (1-based) of the calls that throw */
    public array $throwOnCall = [];

    /** @var (callable(): void)|null called at the start of each publishBatch() */
    public $onPublish;

    /** @param positive-int $maxBatchSize */
    public function __construct(
        private readonly int $maxBatchSize = PHP_INT_MAX,
    ) {}

    public function publishBatch(array $batch): array
    {
        if ($this->onPublish !== null) {
            ($this->onPublish)();
        }
        $ids = array_map(static fn (OutboxRecord $r): string => $r->id, $batch);
        $this->calls[] = $ids;
        if (in_array(count($this->calls), $this->throwOnCall, true)) {
            throw new RuntimeException('channel closed');
        }

        return array_values(array_intersect($ids, $this->refuse));
    }

    public function maxBatchSize(): int
    {
        return $this->maxBatchSize;
    }
}
