<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Time;

use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Clock\Clock;

/**
 * Epoch-milliseconds conversions. NOT a clock — the time source is
 * symfony/clock (swap in MockClock via Clock::set() in tests); these are
 * pure conversion functions kept in one place so the 'Uv' tricks don't
 * scatter across the codebase.
 */
final readonly class EpochMillis
{
    public static function now(): int
    {
        return self::fromDateTime(Clock::get()->now());
    }

    public static function fromDateTime(DateTimeImmutable $dateTime): int
    {
        return (int) $dateTime->format('Uv');
    }

    public static function toDateTime(int $epochMilliseconds): DateTimeImmutable
    {
        $dateTime = DateTimeImmutable::createFromFormat(
            'U.v',
            sprintf('%d.%03d', intdiv($epochMilliseconds, 1000), $epochMilliseconds % 1000),
            new DateTimeZone('UTC'),
        );

        if ($dateTime === false) {
            throw new \InvalidArgumentException("Invalid epoch milliseconds: {$epochMilliseconds}");
        }

        return $dateTime;
    }
}
