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
        if (preg_match('/^([1-9]\d*)([KMG]?)$/i', $size, $matches) !== 1) {
            throw new \InvalidArgumentException(
                "Invalid memory limit '{$size}' — expected <number>[K|M|G], 1 or more, e.g. '128M'",
            );
        }

        return (int) $matches[1] * match (strtoupper($matches[2])) {
            '' => 1,
            'K' => 1_024,
            'M' => 1_048_576,
            'G' => 1_073_741_824,
        };
    }
}
