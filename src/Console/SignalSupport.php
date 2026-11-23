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
            : 'ext-pcntl is not loaded: there is no graceful shutdown. '
                .'As PID 1 of a container, the process ignores SIGTERM and SIGINT until Kubernetes sends SIGKILL at the end of the termination grace period; as another PID, SIGTERM and SIGINT end it at once. '
                .'The current delivery, batch or webhook pass is not completed; the broker redelivers the delivery, the relay publishes the batch again, the webhook rows are due again after their lease, and deduplication absorbs the repeat. '
                .'Load ext-pcntl in the container image.';
    }
}
