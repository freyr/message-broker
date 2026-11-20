<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

/**
 * The rules that each {@see RateLimiter} store shares, as pure functions: the
 * refill of a token bucket, and the max-min division of the global grant
 * between the lanes. The Lua script of a Redis store has the same algorithm,
 * so equal inputs give equal grants in all stores.
 *
 * The availabilities, the grant, the level and the grants are whole numbers.
 * Only the tokens of a bucket have decimals.
 */
final readonly class PermitDivision
{
    /**
     * The tokens of a bucket at $now: the stored tokens plus the rate for the
     * time since $storedAt, at most the burst. A time earlier than the stored
     * time gives a refill of 0.
     *
     * @param int $storedAt epoch milliseconds of the stored tokens
     * @param int $now epoch milliseconds of the present time of the store
     */
    public static function refill(float $tokens, int $storedAt, int $now, Rate $rate): float
    {
        return min($rate->burst, $tokens + max(0, $now - $storedAt) * $rate->perSecond / 1_000);
    }

    /**
     * Divide a whole grant between the lanes, max-min fair. When the sum of
     * the availabilities is the grant or less, each lane gets its
     * availability. Else each lane gets min(availability, L), where the level
     * L is the largest whole number for which the sum of min(availability, L)
     * is the grant or less; the rest goes one permit at a time to the lanes
     * above L, in the order of $available.
     *
     * PHP keeps a numeric lane ID as an integer key; the result has the keys
     * of $available, in the same order.
     *
     * @param array<array-key, int> $available the availability of each lane, in the order of the call
     *
     * @return array<array-key, int> the grant of each lane
     */
    public static function divide(array $available, int $grant): array
    {
        if (array_sum($available) <= $grant) {
            return $available;
        }

        $sorted = $available;
        sort($sorted);
        $remaining = $grant;
        $lanesAboveLevel = count($sorted);
        foreach ($sorted as $availability) {
            if ($availability * $lanesAboveLevel > $remaining) {
                break;
            }
            $remaining -= $availability;
            --$lanesAboveLevel;
        }
        $level = intdiv($remaining, $lanesAboveLevel);
        $rest = $remaining - $level * $lanesAboveLevel;

        $grants = [];
        foreach ($available as $lane => $availability) {
            $share = min($availability, $level);
            if ($rest > 0 && $availability > $level) {
                ++$share;
                --$rest;
            }
            $grants[$lane] = $share;
        }

        return $grants;
    }
}
