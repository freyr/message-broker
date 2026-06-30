<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport;

/**
 * Idle-sleep duration for relay drain loops: the base interval plus up to
 * `jitterMs` of randomization, so many idle lanes do not wake in lockstep.
 */
final class IdleSleep
{
    public static function micros(int $baseMs, int $jitterMs): int
    {
        $base = $baseMs * 1_000;
        if ($jitterMs <= 0) {
            return $base;
        }

        return $base + random_int(0, $jitterMs * 1_000);
    }
}
