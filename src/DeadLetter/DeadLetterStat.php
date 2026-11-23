<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DeadLetter;

/**
 * One group of the dead letter statistics: the matching rows of one message
 * name, source and error class, as DeadLetterStore::stats() returns it.
 */
final readonly class DeadLetterStat
{
    public function __construct(
        public string $messageName,
        public string $source,
        public string $errorClass,
        public int $count,
        public int $oldestFailedAt, // epoch ms
        public int $newestFailedAt, // epoch ms
    ) {}
}
