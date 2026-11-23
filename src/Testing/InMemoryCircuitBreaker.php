<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Webhook\CircuitBreaker;
use Freyr\MessageBroker\Webhook\CircuitBreakerPolicy;
use Freyr\MessageBroker\Webhook\LaneCounts;
use Freyr\MessageBroker\Webhook\LaneState;
use Freyr\MessageBroker\Webhook\LaneStates;
use Freyr\MessageBroker\Webhook\LaneTransitions;

/**
 * In-memory circuit breaker that implements the contracts of
 * {@see CircuitBreaker} and {@see LaneStates} with the window, trip, ignore,
 * return, recovery and block rules of the port and the values of a
 * {@see CircuitBreakerPolicy}. The order of the lane IDs is the byte order of
 * the strings. The time comes from symfony/clock through {@see EpochMillis}.
 *
 * It records each record() call for the assertions of a test.
 */
final class InMemoryCircuitBreaker implements CircuitBreaker, LaneStates
{
    public int $openLanesCalls = 0;

    /** @var list<list<LaneCounts>> the counts of each record() */
    public array $records = [];

    /** @var (callable(int): void)|null called at the start of each record() with the call number */
    public $onRecord;

    /** @var array<string, array{lane: string, windowStartedAt: int, requests: int, failures: int, openUntil: int|null, openedCount: int, consecutiveOpenings: int, blockedAt: int|null}> */
    private array $lanes = [];

    public function __construct(
        private readonly CircuitBreakerPolicy $policy = new CircuitBreakerPolicy(),
    ) {}

    public function openLanes(): array
    {
        ++$this->openLanesCalls;
        $now = EpochMillis::now();
        $open = [];
        foreach ($this->sorted() as $state) {
            if ($state['blockedAt'] !== null || ($state['openUntil'] !== null && $state['openUntil'] > $now)) {
                $open[] = $state['lane'];
            }
        }

        return $open;
    }

    public function record(array $counts): LaneTransitions
    {
        if ($this->onRecord !== null) {
            ($this->onRecord)(count($this->records) + 1);
        }
        $this->records[] = $counts;

        $now = EpochMillis::now();
        usort($counts, static fn (LaneCounts $a, LaneCounts $b): int => strcmp($a->lane, $b->lane));
        $left = [];
        $returned = [];
        $blocked = [];
        foreach ($counts as $count) {
            $key = self::key($count->lane);
            $state = $this->lanes[$key] ?? self::newWindow([
                'lane' => $count->lane,
                'openUntil' => null,
                'openedCount' => 0,
                'consecutiveOpenings' => 0,
                'blockedAt' => null,
            ], $now);
            if ($state['blockedAt'] !== null || ($state['openUntil'] !== null && $state['openUntil'] > $now)) {
                continue;
            }
            if ($state['openUntil'] !== null && $state['windowStartedAt'] < $state['openUntil']) {
                $returned[] = $count->lane;
                $state = self::newWindow($state, $now);
            } elseif ($now - $state['windowStartedAt'] > $this->policy->windowMs) {
                if ($state['requests'] >= $this->policy->minimumRequests) {
                    $state['consecutiveOpenings'] = 0;
                }
                $state = self::newWindow($state, $now);
            }
            $state['requests'] += $count->requests;
            $state['failures'] += $count->failures;
            if ($this->policy->trips($state['requests'], $state['failures'])) {
                $state = self::newWindow($state, $now);
                if ($this->policy->blocks($state['consecutiveOpenings'])) {
                    $blocked[] = $count->lane;
                    $state['blockedAt'] = $now;
                } else {
                    $left[] = $count->lane;
                    $state['openUntil'] = $now + $this->policy->openMs;
                }
                ++$state['openedCount'];
                ++$state['consecutiveOpenings'];
            }
            $this->lanes[$key] = $state;
        }

        return new LaneTransitions($left, $returned, $blocked);
    }

    public function policy(): CircuitBreakerPolicy
    {
        return $this->policy;
    }

    public function all(): array
    {
        return array_map(
            static fn (array $s): LaneState => new LaneState(
                $s['lane'],
                $s['windowStartedAt'],
                $s['requests'],
                $s['failures'],
                $s['openUntil'],
                $s['openedCount'],
                $s['consecutiveOpenings'],
                $s['blockedAt'],
            ),
            $this->sorted(),
        );
    }

    public function close(string $lane): bool
    {
        $key = self::key($lane);
        if (!isset($this->lanes[$key])) {
            return false;
        }
        $state = self::newWindow($this->lanes[$key], EpochMillis::now());
        $state['openUntil'] = null;
        $state['blockedAt'] = null;
        $state['consecutiveOpenings'] = 0;
        $this->lanes[$key] = $state;

        return true;
    }

    /** @return list<array{lane: string, windowStartedAt: int, requests: int, failures: int, openUntil: int|null, openedCount: int, consecutiveOpenings: int, blockedAt: int|null}> */
    private function sorted(): array
    {
        $states = array_values($this->lanes);
        usort($states, static fn (array $a, array $b): int => strcmp($a['lane'], $b['lane']));

        return $states;
    }

    /**
     * @param array{lane: string, openUntil: int|null, openedCount: int, consecutiveOpenings: int, blockedAt: int|null} $state
     *
     * @return array{lane: string, windowStartedAt: int, requests: int, failures: int, openUntil: int|null, openedCount: int, consecutiveOpenings: int, blockedAt: int|null}
     */
    private static function newWindow(array $state, int $now): array
    {
        return [
            'lane' => $state['lane'],
            'windowStartedAt' => $now,
            'requests' => 0,
            'failures' => 0,
            'openUntil' => $state['openUntil'],
            'openedCount' => $state['openedCount'],
            'consecutiveOpenings' => $state['consecutiveOpenings'],
            'blockedAt' => $state['blockedAt'],
        ];
    }

    /** A key that PHP never turns into an integer, also for a numeric lane ID. */
    private static function key(string $lane): string
    {
        return "lane:{$lane}";
    }
}
