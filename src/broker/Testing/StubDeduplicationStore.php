<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\Consumer\DeduplicationStore;
use Freyr\MessageBroker\Consumer\IncomingMessage;

/** Answers acquire() with a fixed result and records the scope it was asked for. */
final class StubDeduplicationStore implements DeduplicationStore
{
    public bool $duplicate = false;

    /** @var list<string> */
    public array $scopes = [];

    public function acquire(IncomingMessage $message, string $consumer): bool
    {
        $this->scopes[] = $consumer;

        return !$this->duplicate;
    }

    public function cleanup(int $beforeEpochMs): int
    {
        return 0;
    }

    public function countOlderThan(int $beforeEpochMs): int
    {
        return 0;
    }
}
