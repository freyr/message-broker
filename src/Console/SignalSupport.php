<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

/**
 * Graceful shutdown (SIGTERM/SIGINT) needs ext-pcntl. Without it the loops can
 * only be stopped with SIGKILL, dropping in-flight work. Pure so the missing
 * branch is testable where the extension is always present.
 */
final class SignalSupport
{
    /** Warning to print when graceful shutdown is unavailable, or null when it is. */
    public static function warning(bool $pcntlLoaded): ?string
    {
        return $pcntlLoaded
            ? null
            : 'ext-pcntl is not loaded: SIGTERM/SIGINT graceful shutdown is unavailable; '
                .'the process can only be stopped with SIGKILL, which drops in-flight work.';
    }
}
