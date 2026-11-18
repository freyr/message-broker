<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

/**
 * Graceful shutdown (SIGTERM/SIGINT) needs ext-pcntl. Without it the signal
 * keeps its default action: it ends the process at once (any PID but 1), or it
 * is ignored until the runtime sends SIGKILL (PID 1 of a container). Pure so
 * the missing branch is testable where the extension is always present.
 */
final class SignalSupport
{
    /** Warning to print when graceful shutdown is unavailable, or null when it is. */
    public static function warning(bool $pcntlLoaded): ?string
    {
        return $pcntlLoaded
            ? null
            : 'ext-pcntl is not loaded: SIGTERM and SIGINT end the process at once, with no graceful shutdown. '
                .'The current delivery or batch is not completed; the broker redelivers the delivery, the relay publishes the batch again, and deduplication absorbs the repeat. '
                .'As PID 1 of a container, the process ignores SIGTERM and SIGINT until the runtime sends SIGKILL after its stop timeout. '
                .'Run it under an init process (for example docker run --init or tini), or load ext-pcntl.';
    }
}
