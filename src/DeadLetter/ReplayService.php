<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DeadLetter;

use Freyr\MessageBroker\Outbox\OutboxStore;

/**
 * Replay = re-enqueue a dead letter into the outbox under its original
 * lane, so redelivery rides the normal relay path (publisher confirms,
 * retry policy, ordering) instead of a side channel. Backs dlq:replay.
 */
final readonly class ReplayService
{
    public function __construct(
        private PdoDeadLetterStore $deadLetters, // @phpstan-ignore property.onlyWritten (skeleton: read once slice 1 implements replay)
        private OutboxStore $outbox, // @phpstan-ignore property.onlyWritten (skeleton: read once slice 1 implements replay)
    ) {}

    public function replay(string $deadLetterId): void
    {
        // TODO slice 1: load row → rebuild OutboxRecord → insert → markReplayed.
    }
}
