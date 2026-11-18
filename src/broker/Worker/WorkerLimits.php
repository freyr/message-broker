<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Worker;

use InvalidArgumentException;

/**
 * The optional limits of one worker run. `null` turns a limit off; with no
 * limit at all the worker runs until it gets a stop signal. A limit is checked
 * between units of work, never inside one.
 */
final readonly class WorkerLimits
{
    /**
     * @param int|null $maxMessages stop after this many messages (relay: rows published)
     * @param int|null $maxSeconds stop after this many seconds since the start of the run
     * @param int|null $maxMemoryBytes stop when memory_get_usage(true) reaches this many bytes
     * @param int|null $maxFailures stop after this many handler failures (consumer only)
     */
    public function __construct(
        public ?int $maxMessages = null,
        public ?int $maxSeconds = null,
        public ?int $maxMemoryBytes = null,
        public ?int $maxFailures = null,
    ) {
        foreach (['maxMessages' => $maxMessages, 'maxSeconds' => $maxSeconds, 'maxMemoryBytes' => $maxMemoryBytes, 'maxFailures' => $maxFailures] as $name => $value) {
            if ($value !== null && $value < 1) {
                throw new InvalidArgumentException("\${$name} must be 1 or more, {$value} given");
            }
        }
    }
}
