<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlCircuitBreaker;
use Freyr\MessageBroker\Webhook\CircuitBreakerPolicy;
use Freyr\MessageBroker\Webhook\LaneCounts;
use Freyr\MessageBroker\Webhook\LaneState;
use Freyr\MessageBroker\Webhook\LaneTransitions;
use PDOException;
use RuntimeException;

/** The PostgreSQL rules of the circuit breaker: the window by the database time, the recovery, the rows and the name cache. */
final class PostgreSqlCircuitBreakerTest extends FunctionalTestCase
{
    private const int WINDOW_MS = 60_000;

    protected function tearDown(): void
    {
        self::$pdo->exec('DROP TRIGGER IF EXISTS lanes_test_trigger ON webhook_lanes');
        self::$pdo->exec('DROP FUNCTION IF EXISTS lanes_test_trigger()');
    }

    public function testSixFailuresAWindowOlderThanTheWindowLengthAndSixMoreFailuresDoNotTrip(): void
    {
        $breaker = $this->breaker();
        $breaker->record([new LaneCounts('crm', 6, 6)]);
        $this->moveWindowBack('crm', self::WINDOW_MS + 1_000);

        self::assertEquals(new LaneTransitions(), $breaker->record([new LaneCounts('crm', 6, 6)]));

        [$state] = $breaker->all();
        self::assertSame(6, $state->requests, 'the old window started again from 0');
        self::assertSame(6, $state->failures);
        self::assertSame([], $breaker->openLanes());
    }

    public function testTheCountsOfAWindowYoungerThanTheWindowLengthAddUp(): void
    {
        $breaker = $this->breaker();
        $breaker->record([new LaneCounts('crm', 6, 6)]);
        $this->moveWindowBack('crm', self::WINDOW_MS - 5_000);

        self::assertEquals(new LaneTransitions(['crm'], []), $breaker->record([new LaneCounts('crm', 6, 6)]));
    }

    public function testAnOldWindowWithTheMinimumNumberOfRequestsIsARecovery(): void
    {
        $this->insertState('crm', requests: 10, consecutiveOpenings: 2);
        $breaker = $this->breaker();

        $breaker->record([new LaneCounts('crm', 1, 0)]);

        [$state] = $breaker->all();
        self::assertSame(0, $state->consecutiveOpenings);
        self::assertSame(1, $state->requests);
    }

    public function testAnOldWindowWithFewerRequestsThanTheMinimumIsNoRecovery(): void
    {
        $this->insertState('crm', requests: 9, consecutiveOpenings: 2);
        $breaker = $this->breaker();

        $breaker->record([new LaneCounts('crm', 1, 0)]);

        [$state] = $breaker->all();
        self::assertSame(2, $state->consecutiveOpenings);
        self::assertSame(1, $state->requests, 'the old window started again');
    }

    public function testAReturnIsNoRecoveryAlsoWhenTheWindowWasOld(): void
    {
        $this->insertState('crm', requests: 10, consecutiveOpenings: 2, openUntilAgoMs: 1_000);
        $breaker = $this->breaker();

        self::assertEquals(new LaneTransitions([], ['crm']), $breaker->record([new LaneCounts('crm', 1, 0)]));

        [$state] = $breaker->all();
        self::assertSame(2, $state->consecutiveOpenings);
    }

    public function testATripAtTheMaximumOfOpeningsInARowSetsBlockedAtAndKeepsOpenUntil(): void
    {
        $this->insertState('crm', requests: 0, consecutiveOpenings: 3, openUntilAgoMs: 1_000);
        $openUntil = $this->breaker()->all()[0]->openUntil;
        $breaker = $this->breaker();

        self::assertEquals(new LaneTransitions([], ['crm'], ['crm']), $breaker->record([new LaneCounts('crm', 10, 10)]));

        [$state] = $breaker->all();
        self::assertNotNull($state->blockedAt);
        self::assertSame($state->windowStartedAt, $state->blockedAt, 'the trip starts a new window at the time of the block');
        self::assertSame($openUntil, $state->openUntil);
        self::assertSame(4, $state->consecutiveOpenings);
        self::assertSame(['crm'], $breaker->openLanes());
    }

    public function testAnOpenTimeAndAWindowAboveTheRangeOfA32BitIntegerWork(): void
    {
        $thirtyDays = 30 * 86_400_000;
        $breaker = new PostgreSqlCircuitBreaker(self::$pdo, new CircuitBreakerPolicy(windowMs: $thirtyDays, openMs: $thirtyDays));

        self::assertEquals(new LaneTransitions(['crm'], []), $breaker->record([new LaneCounts('crm', 10, 10)]));

        [$state] = $breaker->all();
        self::assertSame($state->windowStartedAt + $thirtyDays, $state->openUntil);
        self::assertSame(['crm'], $breaker->openLanes());
    }

    public function testAMinimumAndAMaximumAboveTheRangeOfA32BitIntegerWork(): void
    {
        $breaker = new PostgreSqlCircuitBreaker(self::$pdo, new CircuitBreakerPolicy(minimumRequests: 3_000_000_000, maxConsecutiveOpenings: 3_000_000_000));

        self::assertEquals(new LaneTransitions(), $breaker->record([new LaneCounts('crm', 10, 10)]), 'below the minimum');
    }

    public function testANumericLaneIdIsAStringInEachResult(): void
    {
        $breaker = $this->breaker();

        self::assertEquals(new LaneTransitions(['42'], []), $breaker->record([new LaneCounts('42', 10, 10)]));
        self::assertSame(['42'], $breaker->openLanes());
        self::assertSame('42', $breaker->all()[0]->lane);
        self::assertTrue($breaker->close('42'));
    }

    public function testTheStoreSkipsTheInsertForTheLanesThatItAlreadySaw(): void
    {
        $breaker = $this->breaker();
        $breaker->record([new LaneCounts('crm', 1, 0)]);
        $breaker->record([new LaneCounts('erp', 1, 0)]);
        $this->createTrigger('BEFORE INSERT', 'STATEMENT', "RAISE EXCEPTION 'insert into webhook_lanes'");

        $breaker->record([new LaneCounts('crm', 1, 0)]);
        $breaker->record([new LaneCounts('erp', 1, 0)]);
        self::assertSame(
            [2, 2],
            array_map(static fn (LaneState $state): int => $state->requests, $breaker->all()),
            'no INSERT statement: the store keeps the lanes of each earlier call',
        );

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('insert into webhook_lanes');
        $this->breaker()->record([new LaneCounts('crm', 1, 0)]);
    }

    public function testALaneThatIsNewInALaterCallGetsItsRow(): void
    {
        $breaker = $this->breaker();
        $breaker->record([new LaneCounts('crm', 1, 0)]);

        $breaker->record([new LaneCounts('crm', 1, 0), new LaneCounts('erp', 2, 1)]);

        self::assertSame(
            [['crm', 2, 0], ['erp', 2, 1]],
            array_map(static fn (LaneState $state): array => [$state->lane, $state->requests, $state->failures], $breaker->all()),
        );
    }

    public function testARowThatADifferentConnectionDeletedIsInsertedAgain(): void
    {
        $breaker = $this->breaker();
        $breaker->record([new LaneCounts('crm', 3, 1), new LaneCounts('erp', 2, 0)]);
        self::newConnection()->exec("DELETE FROM webhook_lanes WHERE lane = 'erp'");

        $breaker->record([new LaneCounts('crm', 3, 1), new LaneCounts('erp', 2, 0)]);

        $states = $breaker->all();
        self::assertSame([6, 2], array_map(static fn (LaneState $state): int => $state->requests, $states), 'the first write of the call wrote nothing');
    }

    public function testARowThatIsStillMissingAfterTheSecondInsertThrows(): void
    {
        $this->createTrigger('BEFORE INSERT', 'ROW', 'RETURN NULL');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A row of webhook_lanes is missing after its insert, two times.');
        $this->breaker()->record([new LaneCounts('crm', 1, 0)]);
    }

    public function testACallWithNoCountsChangesNothing(): void
    {
        self::assertEquals(new LaneTransitions(), $this->breaker()->record([]));
        self::assertSame([], $this->breaker()->all());
    }

    private function breaker(): PostgreSqlCircuitBreaker
    {
        return new PostgreSqlCircuitBreaker(self::$pdo, new CircuitBreakerPolicy(windowMs: self::WINDOW_MS, openMs: 600_000));
    }

    private function moveWindowBack(string $lane, int $milliseconds): void
    {
        $statement = self::$pdo->prepare("UPDATE webhook_lanes SET window_started_at = window_started_at - CAST(:ms AS INTEGER) * INTERVAL '1 millisecond' WHERE lane = :lane");
        $statement->execute([
            'ms' => $milliseconds,
            'lane' => $lane,
        ]);
    }

    /** A lane whose window is older than the window length; with $openUntilAgoMs, an open time that ended after the window started. */
    private function insertState(string $lane, int $requests, int $consecutiveOpenings, ?int $openUntilAgoMs = null): void
    {
        $statement = self::$pdo->prepare(
            "INSERT INTO webhook_lanes (lane, window_started_at, requests, failures, open_until, opened_count, consecutive_openings)
             VALUES (:lane, CAST(now() AT TIME ZONE 'UTC' AS TIMESTAMP(3)) - CAST(:window_ago AS INTEGER) * INTERVAL '1 millisecond', :requests, 0,
                 CAST(now() AT TIME ZONE 'UTC' AS TIMESTAMP(3)) - CAST(:open_until_ago AS INTEGER) * INTERVAL '1 millisecond', :consecutive, :consecutive)",
        );
        $statement->execute([
            'lane' => $lane,
            'window_ago' => self::WINDOW_MS + 5_000,
            'requests' => $requests,
            'open_until_ago' => $openUntilAgoMs,
            'consecutive' => $consecutiveOpenings,
        ]);
    }

    private function createTrigger(string $timing, string $level, string $body): void
    {
        self::$pdo->exec("CREATE FUNCTION lanes_test_trigger() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN {$body}; END \$\$");
        self::$pdo->exec("CREATE TRIGGER lanes_test_trigger {$timing} ON webhook_lanes FOR EACH {$level} EXECUTE FUNCTION lanes_test_trigger()");
    }
}
