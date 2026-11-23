<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

/**
 * Parses CLI memory sizes ('512', '64K', '128M', '1G') to bytes. The units are
 * binary and case-insensitive; a plain number is bytes; the value is 1 or more.
 */
final readonly class ByteSize
{
    public static function toBytes(string $size): int
    {
        if (preg_match('/^([1-9]\d*)([KMG]?)\z/i', $size, $matches) !== 1) {
            throw self::invalid($size, 'expected <number>[K|M|G], 1 or more, e.g. \'128M\'');
        }

        $unit = match (strtoupper($matches[2])) {
            'K' => 1_024,
            'M' => 1_048_576,
            'G' => 1_073_741_824,
            default => 1,
        };
        $count = (int) $matches[1];
        // (int) of a digit string above PHP_INT_MAX gives PHP_INT_MAX, so compare the text too.
        if ((string) $count !== $matches[1] || $count > intdiv(PHP_INT_MAX, $unit)) {
            throw self::invalid($size, 'the value is larger than PHP_INT_MAX bytes');
        }

        return $count * $unit;
    }

    private static function invalid(string $size, string $reason): \InvalidArgumentException
    {
        return new \InvalidArgumentException("Invalid memory limit '{$size}' — {$reason}");
    }
}
