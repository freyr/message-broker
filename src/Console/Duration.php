<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

/**
 * Parses CLI duration strings ('30s', '15m', '24h', '7d') to milliseconds.
 */
final readonly class Duration
{
    public static function toMilliseconds(string $duration): int
    {
        if (preg_match('/^(\d+)(s|m|h|d)$/', $duration, $matches) !== 1) {
            throw new \InvalidArgumentException(
                "Invalid duration '{$duration}' — expected <number><s|m|h|d>, e.g. '7d'",
            );
        }

        $value = (int) $matches[1];

        return $value * match ($matches[2]) {
            's' => 1_000,
            'm' => 60_000,
            'h' => 3_600_000,
            'd' => 86_400_000,
        };
    }
}
