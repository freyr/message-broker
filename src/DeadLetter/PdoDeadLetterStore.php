<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DeadLetter;

use Freyr\MessageBroker\Storage\Platform;
use PDO;

final readonly class PdoDeadLetterStore
{
    public function __construct(
        private PDO $pdo,
        private Platform $platform,
    ) {}

    public function store(DeadLetter $deadLetter): void
    {
        // TODO slice 1
    }

    /** @return list<DeadLetter> */
    public function list(?string $messageName = null, ?string $source = null, ?int $sinceMs = null): array
    {
        // TODO slice 1: backs the dlq:list / dlq:show commands.
        return [];
    }

    /** Replay keeps the row for audit — marks replayed_at instead of deleting. */
    public function markReplayed(string $id): void
    {
        // TODO slice 1
    }

    public function purge(?int $olderThanMs = null): int
    {
        // TODO slice 1
        return 0;
    }
}
