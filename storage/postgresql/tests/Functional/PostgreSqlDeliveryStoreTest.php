<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeadLetterStore;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeliveryRetention;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeliveryStore;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Webhook\ClaimedDelivery;
use Freyr\MessageBroker\Webhook\DeliveryCompletion;
use Freyr\MessageBroker\Webhook\DeliveryRecord;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClassConstant;
use Symfony\Component\Uid\Uuid;

/**
 * The PostgreSQL parts of the delivery store that the contract test case
 * does not state: concurrent claimers, the claim over many lanes and its
 * plan, the stored final state, the rollback of complete(), and the open row
 * rule of the dead letters across a replay.
 */
final class PostgreSqlDeliveryStoreTest extends FunctionalTestCase
{
    private const string LANE = 'store';
    private const int LEASE_MS = 60_000;

    private PostgreSqlDeliveryStore $store;
    private PostgreSqlDeadLetterStore $deadLetters;

    protected function setUp(): void
    {
        parent::setUp();
        // Rows of the last seconds can belong to the day before, just past midnight.
        (new PostgreSqlDeliveryRetention(self::$pdo))->ensureCapacity(2, (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('-1 day'));
        $this->store = new PostgreSqlDeliveryStore(self::$pdo);
        $this->deadLetters = new PostgreSqlDeadLetterStore(self::$pdo);
    }

    public function testTwoSessionsThatClaimAtTheSameTimeGetDifferentRows(): void
    {
        $rows = [$this->record(), $this->record(), $this->record(), $this->record()];
        $this->store->insert($rows);
        $rival = self::newConnection();
        $rival->exec("SET lock_timeout = '2s'");
        self::$pdo->exec("SET lock_timeout = '2s'");

        // The claim of the rival runs inside its open transaction, so it holds
        // its row locks while the second session claims.
        $rival->beginTransaction();
        $first = (new PostgreSqlDeliveryStore($rival))->claim(self::each([self::LANE], 2), 10, self::LEASE_MS);
        $second = $this->store->claim(self::each([self::LANE], 10), 10, self::LEASE_MS);
        $rival->commit();
        self::$pdo->exec('RESET lock_timeout');

        self::assertCount(2, $first);
        self::assertCount(2, $second, 'the second session skips the locked rows and does not wait');
        self::assertSame([], array_intersect(self::claimedIds($first), self::claimedIds($second)));
        self::assertEqualsCanonicalizing(self::ids($rows), [...self::claimedIds($first), ...self::claimedIds($second)]);
        self::assertSame([], $this->store->claim(self::each([self::LANE], 10), 10, self::LEASE_MS), 'both leases hold');
    }

    public function testAClaimOverTwentyLanesWithOneLaneOfTenThousandDueRowsReturnsRowsOfEachLane(): void
    {
        $lanes = $this->insertTwentyLanesWithOneBacklog();

        $claimed = $this->store->claim(self::each($lanes, 10), 200, self::LEASE_MS);

        $expected = array_fill_keys($lanes, 2);
        $expected['busy'] = 10;
        ksort($expected);
        self::assertSame($expected, self::countByLane($claimed));
    }

    /** @return iterable<string, array{string}> */
    public static function laneStatements(): iterable
    {
        yield 'claim' => ['CLAIM_SQL'];
        yield 'due counts' => ['DUE_COUNTS_SQL'];
    }

    #[DataProvider('laneStatements')]
    public function testThePlanReadsThePartialIndexOfEachPartitionForEachLaneAndNoTableScan(string $statement): void
    {
        $lanes = $this->insertTwentyLanesWithOneBacklog();

        $plan = $this->explain($statement, $lanes);

        $nodes = self::nodes($plan);
        self::assertSame([], array_values(array_filter($nodes, static fn (array $n): bool => $n['Node Type'] === 'Seq Scan')), 'no sequential scan');
        $dueScans = array_values(array_filter(
            $nodes,
            static fn (array $n): bool => str_ends_with((string) ($n['Index Name'] ?? ''), '_lane_available_at_idx'),
        ));
        self::assertEqualsCanonicalizing(
            $this->partitionIndexesOf('idx_webhook_deliveries_due'),
            array_values(array_unique(array_map(static fn (array $n): string => (string) $n['Index Name'], $dueScans))),
            'the partial index of each partition',
        );
        foreach ($dueScans as $scan) {
            self::assertContains($scan['Node Type'], ['Index Scan', 'Index Only Scan']);
            self::assertStringContainsString('lane', (string) $scan['Index Cond'], 'one index start for each lane');
        }
        self::assertContains('Function Scan', array_column($nodes, 'Node Type'), 'the loop over the lane list');
    }

    public function testThePlanOfTheDueCountsTakesNoRowLock(): void
    {
        $lanes = $this->insertTwentyLanesWithOneBacklog();

        $nodes = self::nodes($this->explain('DUE_COUNTS_SQL', $lanes));

        self::assertNotContains('LockRows', array_column($nodes, 'Node Type'));
        self::assertNotContains('ModifyTable', array_column($nodes, 'Node Type'));
    }

    public function testTheDueCountsOverTwentyLanesCapTheBusyLane(): void
    {
        $lanes = $this->insertTwentyLanesWithOneBacklog();

        $counts = $this->store->dueCounts($lanes, 25);

        $expected = array_fill_keys($lanes, 2);
        $expected['busy'] = 25;
        self::assertSame($expected, $counts, 'each lane in the order of the list');
    }

    public function testTheDueCountsDoNotWaitForTheRowsThatADifferentSessionLocksAndCountThem(): void
    {
        $this->store->insert([$this->record(), $this->record(), $this->record()]);
        $rival = self::newConnection();
        self::$pdo->exec("SET lock_timeout = '2s'");

        // The rival holds the row locks of its claim in an open transaction;
        // its leases are not visible yet, so the rows are still due here.
        $rival->beginTransaction();
        (new PostgreSqlDeliveryStore($rival))->claim([
            self::LANE => 2,
        ], 2, self::LEASE_MS);
        $counts = $this->store->dueCounts([self::LANE], 10);
        $rival->commit();
        self::$pdo->exec('RESET lock_timeout');

        self::assertSame([
            self::LANE => 3,
        ], $counts, 'a read with no lock');
        self::assertSame([
            self::LANE => 1,
        ], $this->store->dueCounts([self::LANE], 10), 'after the commit of the rival only one row is due');
    }

    public function testCompleteReturnsAListOfTheChangedRowsInTheOrderOfTheOutcomes(): void
    {
        $late = $this->record();
        $first = $this->record();
        $second = $this->record();
        $this->store->insert([$late]);
        $old = $this->store->claim(self::each([self::LANE], 10), 10, 0)[0];
        $this->claimOne();
        $this->store->insert([$first, $second]);
        [$a, $b] = self::byId($this->store->claim(self::each([self::LANE], 10), 10, self::LEASE_MS), [$first->id, $second->id]);

        $changed = $this->store->complete([
            DeliveryCompletion::delivered($old),
            DeliveryCompletion::delivered($b),
            DeliveryCompletion::retry($a, EpochMillis::now() + 60_000),
        ]);

        self::assertSame([$second->id, $first->id], $changed);
    }

    public function testALaneWithQuotesCommasAndBracesIsClaimed(): void
    {
        $lane = 'a"b,c{d}\\e\'f';
        $row = $this->record(lane: $lane);
        $this->store->insert([$row]);

        $claimed = $this->store->claim(self::each([$lane], 10), 10, self::LEASE_MS);

        self::assertSame([$row->id], self::claimedIds($claimed));
        self::assertSame($lane, $claimed[0]->delivery->lane);
    }

    public function testTheLeaseIsTheStoredAvailableAtAndTheDatabaseTimeWithMilliseconds(): void
    {
        $row = $this->record();
        $this->store->insert([$row]);

        $claimed = $this->store->claim(self::each([self::LANE], 10), 10, self::LEASE_MS);

        self::assertSame($claimed[0]->lease, self::fetchInt(
            "SELECT CAST(EXTRACT(EPOCH FROM available_at) * 1000 AS BIGINT) FROM webhook_deliveries WHERE id = '{$row->id}'",
        ));
        $databaseNow = self::fetchInt("SELECT CAST(EXTRACT(EPOCH FROM CAST(now() AT TIME ZONE 'UTC' AS TIMESTAMP(3))) * 1000 AS BIGINT)");
        self::assertGreaterThanOrEqual($databaseNow + self::LEASE_MS - 2_000, $claimed[0]->lease);
        self::assertLessThanOrEqual($databaseNow + self::LEASE_MS, $claimed[0]->lease);
    }

    public function testAnInsertJoinsTheTransactionOfTheCaller(): void
    {
        self::$pdo->beginTransaction();
        $this->store->insert([$this->record()]);
        self::$pdo->rollBack();

        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM webhook_deliveries'));
    }

    public function testAnInsertKeepsTheAttemptsAndTheAvailableAtOfTheRecord(): void
    {
        $availableAt = intdiv(EpochMillis::now(), 1000) * 1000 + 60_789;
        $row = new DeliveryRecord(
            id: self::newId(),
            messageId: self::newId(),
            lane: self::LANE,
            messageName: 'order.placed',
            body: '{}',
            headers: [],
            createdAt: EpochMillis::now() - 1_000,
            attempts: 3,
            availableAt: $availableAt,
        );

        $this->store->insert([$row]);

        self::assertSame(
            [
                'attempts' => 3,
                'available_at' => $availableAt,
            ],
            $this->rowState($row->id, 'attempts, CAST(EXTRACT(EPOCH FROM available_at) * 1000 AS BIGINT) AS available_at'),
        );
    }

    public function testADeliveredOutcomeStoresTheFinalStateDelivered(): void
    {
        $row = $this->record();
        $this->store->insert([$row]);
        $claimed = $this->claimOne();

        $this->store->complete([DeliveryCompletion::delivered($claimed)]);

        $state = $this->rowState($row->id);
        self::assertSame(1, $state['outcome']);
        self::assertSame(1, $state['attempts']);
        self::assertTrue($state['completed']);
        self::assertSame($claimed->lease, $state['available_at'], 'a delivered outcome keeps the available_at');
    }

    public function testADeadOutcomeStoresTheFinalStateDead(): void
    {
        $row = $this->record();
        $this->store->insert([$row]);
        $claimed = $this->claimOne();

        $this->store->complete([DeliveryCompletion::dead($claimed, $this->deadLetter($claimed, attempts: 8))]);

        $state = $this->rowState($row->id);
        self::assertSame(2, $state['outcome']);
        self::assertSame(8, $state['attempts']);
        self::assertTrue($state['completed']);
    }

    public function testARetryOutcomeLeavesTheRowUnfinished(): void
    {
        $row = $this->record();
        $this->store->insert([$row]);
        $claimed = $this->claimOne();
        $retryAt = intdiv(EpochMillis::now(), 1000) * 1000 + 30_456;

        $this->store->complete([DeliveryCompletion::retry($claimed, $retryAt)]);

        self::assertSame(
            [
                'outcome' => null,
                'attempts' => 1,
                'completed' => false,
                'available_at' => $retryAt,
            ],
            $this->rowState($row->id),
        );
    }

    public function testACompleteWithAnOldLeaseChangesNoColumnOfTheRowAndWritesNoDeadLetter(): void
    {
        $row = $this->record();
        $this->store->insert([$row]);
        $old = $this->store->claim(self::each([self::LANE], 10), 10, 0)[0];
        $current = $this->claimOne();

        $changed = $this->store->complete([
            DeliveryCompletion::dead($old, $this->deadLetter($old)),
            DeliveryCompletion::retry($old, EpochMillis::now() - 1_000),
            DeliveryCompletion::delivered($old),
        ]);

        self::assertSame([], $changed);
        self::assertSame(
            [
                'outcome' => null,
                'attempts' => 0,
                'completed' => false,
                'available_at' => $current->lease,
            ],
            $this->rowState($row->id),
        );
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM dead_letters'));
    }

    public function testTheFirstOutcomeOfARowInOneCallWins(): void
    {
        $row = $this->record();
        $this->store->insert([$row]);
        $claimed = $this->claimOne();

        $changed = $this->store->complete([
            DeliveryCompletion::delivered($claimed),
            DeliveryCompletion::dead($claimed, $this->deadLetter($claimed)),
        ]);

        self::assertSame([$row->id], $changed);
        self::assertSame(1, $this->rowState($row->id)['outcome']);
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM dead_letters'));
    }

    public function testTheDeadLetterInsertAndTheFinalStatesRollBackTogetherWhenTheInsertFails(): void
    {
        $delivered = $this->record();
        $dead = $this->record();
        $this->store->insert([$delivered, $dead]);
        [$a, $b] = self::byId($this->store->claim(self::each([self::LANE], 10), 10, self::LEASE_MS), [$delivered->id, $dead->id]);
        $good = $this->deadLetter($b);
        $broken = new DeadLetter(
            id: 'not-a-uuid', // the id column is UUID: the insert fails
            source: $good->source,
            messageId: $good->messageId,
            messageName: $good->messageName,
            body: $good->body,
            headers: [],
            errorClass: 'failed',
            errorMessage: '500 boom',
            errorTrace: '',
            attempts: 1,
            failedAt: EpochMillis::now(),
        );

        try {
            $this->store->complete([DeliveryCompletion::delivered($a), DeliveryCompletion::dead($b, $broken)]);
            self::fail('the failed insert must propagate');
        } catch (PDOException $error) {
            self::assertStringContainsString('invalid input syntax for type uuid', $error->getMessage());
        }

        self::assertFalse(self::$pdo->inTransaction(), 'the transaction is rolled back');
        foreach ([$delivered->id, $dead->id] as $id) {
            self::assertSame(
                [
                    'outcome' => null,
                    'attempts' => 0,
                    'completed' => false,
                    'available_at' => $a->lease,
                ],
                $this->rowState($id),
            );
        }
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM dead_letters'));
        self::assertSame(
            [$delivered->id],
            $this->store->complete([DeliveryCompletion::delivered($a)]),
            'the leases still hold, and the connection works',
        );
    }

    public function testTheDeadLettersOfOneMessageInTwoLanesAreTwoOpenRows(): void
    {
        $messageId = self::newId();
        $this->store->insert([$this->record(messageId: $messageId), $this->record(lane: 'store_other', messageId: $messageId)]);
        $claimed = $this->store->claim(self::each([self::LANE, 'store_other'], 10), 10, self::LEASE_MS);

        $this->store->complete(array_map(
            fn (ClaimedDelivery $c): DeliveryCompletion => DeliveryCompletion::dead($c, $this->deadLetter($c)),
            $claimed,
        ));

        self::assertSame(2, self::fetchInt(
            "SELECT COUNT(*) FROM dead_letters WHERE message_id = '{$messageId}' AND replayed_at IS NULL",
        ));
    }

    public function testASecondFailureOfAMessageInTheSameLaneAfterAReplayGivesANewRow(): void
    {
        $messageId = self::newId();
        $this->store->insert([$this->record(messageId: $messageId)]);
        $first = $this->claimOne();
        $firstLetter = $this->deadLetter($first);
        $this->store->complete([DeliveryCompletion::dead($first, $firstLetter)]);
        self::assertTrue($this->deadLetters->markReplayed($firstLetter->id));

        // The replay inserts a new delivery row with the message ID of the dead letter.
        $this->store->insert([$this->record(messageId: $messageId)]);
        $second = $this->claimOne();
        $secondLetter = $this->deadLetter($second);
        $this->store->complete([DeliveryCompletion::dead($second, $secondLetter)]);

        $rows = $this->deadLetters->list(new DeadLetterFilter(messageId: $messageId));
        self::assertEqualsCanonicalizing([$firstLetter->id, $secondLetter->id], array_map(static fn (DeadLetter $d): string => $d->id, $rows));
        $open = $this->deadLetters->list(new DeadLetterFilter(messageId: $messageId, replayed: false));
        self::assertSame([$secondLetter->id], array_map(static fn (DeadLetter $d): string => $d->id, $open));
    }

    public function testAnAbandonSkipsARowThatADifferentSessionLocks(): void
    {
        $locked = $this->record(createdAt: EpochMillis::now() - 50_000);
        $free = $this->record(lane: 'store_other', createdAt: EpochMillis::now() - 50_000);
        $this->store->insert([$locked, $free]);
        $rival = self::newConnection();
        $rival->exec("SET lock_timeout = '2s'");
        self::$pdo->exec("SET lock_timeout = '2s'");

        // The claim of the rival runs inside its open transaction, so it holds the row lock.
        $rival->beginTransaction();
        (new PostgreSqlDeliveryStore($rival))->claim(self::each([self::LANE], 10), 10, self::LEASE_MS);
        $first = $this->store->abandon(EpochMillis::now(), null, 10);
        $rival->commit();
        self::$pdo->exec('RESET lock_timeout');

        self::assertSame(1, $first, 'the locked row is skipped, with no wait');
        self::assertTrue($this->rowState($free->id)['completed']);
        self::assertFalse($this->rowState($locked->id)['completed']);
        self::assertSame(1, $this->store->abandon(EpochMillis::now(), null, 10), 'after the commit of the rival, its leased row is abandoned');
        self::assertSame(0, $this->store->abandon(EpochMillis::now(), null, 10));
    }

    public function testAnAbandonStoresTheFinalStateDeadAndKeepsTheAttemptsAndTheAvailableAt(): void
    {
        $availableAt = intdiv(EpochMillis::now(), 1000) * 1000 + 60_789;
        $row = new DeliveryRecord(
            id: self::newId(),
            messageId: self::newId(),
            lane: self::LANE,
            messageName: 'order.placed',
            body: '{}',
            headers: [],
            createdAt: EpochMillis::now() - 50_000,
            attempts: 4,
            availableAt: $availableAt,
        );
        $this->store->insert([$row]);

        self::assertSame(1, $this->store->abandon(EpochMillis::now(), [self::LANE, self::LANE], 10));

        self::assertSame(
            [
                'outcome' => 2,
                'attempts' => 4,
                'completed' => true,
                'available_at' => $availableAt,
            ],
            $this->rowState($row->id),
        );
    }

    public function testTheDeadLetterInsertAndTheFinalStatesOfAnAbandonRollBackTogether(): void
    {
        $first = $this->record(createdAt: EpochMillis::now() - 50_000);
        $second = $this->record(createdAt: EpochMillis::now() - 40_000);
        $this->store->insert([$first, $second]);
        // The second dead letter insert fails in the database.
        self::$pdo->exec(
            "ALTER TABLE dead_letters ADD CONSTRAINT test_abandon_fails CHECK (message_id <> '{$second->messageId}') NOT VALID",
        );

        try {
            $this->store->abandon(EpochMillis::now(), null, 10);
            self::fail('the failed insert must propagate');
        } catch (PDOException $error) {
            self::assertStringContainsString('test_abandon_fails', $error->getMessage());
        } finally {
            self::$pdo->exec('ALTER TABLE dead_letters DROP CONSTRAINT IF EXISTS test_abandon_fails');
        }

        self::assertFalse(self::$pdo->inTransaction(), 'the transaction is rolled back');
        self::assertFalse($this->rowState($first->id)['completed']);
        self::assertFalse($this->rowState($second->id)['completed']);
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM dead_letters'));
        self::assertSame(2, $this->store->abandon(EpochMillis::now(), null, 10), 'the connection works');
    }

    /** @return list<string> the lanes: "busy" with 10,000 due rows, and 19 lanes with 2 due rows each */
    private function insertTwentyLanesWithOneBacklog(): array
    {
        $lanes = ['busy'];
        for ($i = 1; $i <= 19; ++$i) {
            $lanes[] = sprintf('lane_%02d', $i);
        }
        self::$pdo->exec(
            "INSERT INTO webhook_deliveries (id, message_id, lane, message_name, body, headers, created_at, available_at)
             SELECT gen_random_uuid(), gen_random_uuid(), 'busy', 'order.placed', '\\x7b7d', '{}', t, t
             FROM generate_series(1, 10000), LATERAL (SELECT CAST(now() AT TIME ZONE 'UTC' AS TIMESTAMP(3)) AS t) AS clock",
        );
        // Finished rows stay in the table until their partition expires: 5,000
        // in each partition (the day before, today and tomorrow).
        self::$pdo->exec(
            "INSERT INTO webhook_deliveries (id, message_id, lane, message_name, body, headers, created_at, available_at, completed_at, outcome)
             SELECT gen_random_uuid(), gen_random_uuid(), 'lane_' || lpad(CAST(1 + n % 19 AS TEXT), 2, '0'), 'order.placed', '\\x7b7d', '{}', t, t, t, 1
             FROM generate_series(1, 15000) AS n,
                  LATERAL (SELECT CAST(now() AT TIME ZONE 'UTC' AS TIMESTAMP(3)) + (n % 3 - 1) * INTERVAL '1 day' AS t) AS clock",
        );
        $records = [];
        foreach (array_slice($lanes, 1) as $lane) {
            $records[] = $this->record(lane: $lane);
            $records[] = $this->record(lane: $lane);
        }
        $this->store->insert($records);
        self::$pdo->exec('ANALYZE webhook_deliveries');
        shuffle($lanes);

        return $lanes;
    }

    /**
     * @param string $constant CLAIM_SQL (a row limit of 10 for each lane and a total of 200) or DUE_COUNTS_SQL (a cap of 25)
     * @param list<string> $lanes
     *
     * @return array<string, mixed> the plan of the statement, as EXPLAIN (FORMAT JSON) gives it
     */
    private function explain(string $constant, array $lanes): array
    {
        $sql = (new ReflectionClassConstant(PostgreSqlDeliveryStore::class, $constant))->getValue();
        self::assertIsString($sql);
        $statement = self::$pdo->prepare('EXPLAIN (FORMAT JSON) '.$sql);
        if ($constant === 'CLAIM_SQL') {
            $statement->bindValue('lanes', json_encode(array_map(static fn (string $lane): array => [$lane, 10], $lanes), JSON_THROW_ON_ERROR));
            $statement->bindValue('total', 200, PDO::PARAM_INT);
            $statement->bindValue('lease_ms', self::LEASE_MS, PDO::PARAM_INT);
        } else {
            $statement->bindValue('lanes', json_encode($lanes, JSON_THROW_ON_ERROR));
            $statement->bindValue('cap', 25, PDO::PARAM_INT);
        }
        $statement->execute();
        $json = $statement->fetchColumn();
        self::assertIsString($json);
        $plan = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($plan);
        self::assertIsArray($plan[0]['Plan'] ?? null);

        /** @var array<string, mixed> */
        return $plan[0]['Plan'];
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return list<array<string, mixed>> the node and all nodes below it
     */
    private static function nodes(array $node): array
    {
        $all = [$node];
        foreach (is_array($node['Plans'] ?? null) ? $node['Plans'] : [] as $child) {
            self::assertIsArray($child);
            /** @var array<string, mixed> $child */
            array_push($all, ...self::nodes($child));
        }

        return $all;
    }

    /** @return list<string> */
    private function partitionIndexesOf(string $index): array
    {
        $statement = self::$pdo->query(
            "SELECT c.relname FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid WHERE i.inhparent = '{$index}'::regclass",
        );
        self::assertNotFalse($statement);

        /** @var list<string> */
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @param list<string> $lanes
     *
     * @return array<string, int> the same row limit for each lane, in the order of the list
     */
    private static function each(array $lanes, int $limit): array
    {
        return array_fill_keys($lanes, $limit);
    }

    private function claimOne(): ClaimedDelivery
    {
        $claimed = $this->store->claim(self::each([self::LANE], 10), 10, self::LEASE_MS);
        self::assertCount(1, $claimed);

        return $claimed[0];
    }

    /** @return array<string, mixed> */
    private function rowState(
        string $id,
        string $columns = 'outcome, attempts, completed_at IS NOT NULL AS completed, CAST(EXTRACT(EPOCH FROM available_at) * 1000 AS BIGINT) AS available_at',
    ): array {
        $statement = self::$pdo->query("SELECT {$columns} FROM webhook_deliveries WHERE id = '{$id}'");
        self::assertNotFalse($statement);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        /** @var array<string, mixed> */
        return $row;
    }

    private function record(
        string $lane = self::LANE,
        ?string $messageId = null,
        ?int $createdAt = null,
        ?int $availableAt = null,
    ): DeliveryRecord {
        return new DeliveryRecord(
            id: self::newId(),
            messageId: $messageId ?? self::newId(),
            lane: $lane,
            messageName: 'order.placed',
            body: '{"order_id":"o-1"}',
            headers: [],
            createdAt: $createdAt ?? EpochMillis::now() - 1_000,
            availableAt: $availableAt,
        );
    }

    private function deadLetter(ClaimedDelivery $claimed, int $attempts = 1): DeadLetter
    {
        $record = $claimed->delivery;

        return new DeadLetter(
            id: self::newId(),
            source: 'webhook:'.$record->lane,
            messageId: $record->messageId,
            messageName: $record->messageName,
            body: $record->body,
            headers: $record->headers,
            errorClass: 'failed',
            errorMessage: '500 boom',
            errorTrace: '',
            attempts: $attempts,
            failedAt: EpochMillis::now(),
        );
    }

    private static function newId(): string
    {
        return Uuid::v7()->toString();
    }

    /**
     * @param list<DeliveryRecord> $records
     *
     * @return list<string>
     */
    private static function ids(array $records): array
    {
        return array_map(static fn (DeliveryRecord $r): string => $r->id, $records);
    }

    /**
     * @param list<ClaimedDelivery> $claimed
     *
     * @return list<string>
     */
    private static function claimedIds(array $claimed): array
    {
        return array_map(static fn (ClaimedDelivery $c): string => $c->delivery->id, $claimed);
    }

    /**
     * @param list<ClaimedDelivery> $claimed
     *
     * @return array<string, int>
     */
    private static function countByLane(array $claimed): array
    {
        $counts = [];
        foreach ($claimed as $c) {
            $counts[$c->delivery->lane] = ($counts[$c->delivery->lane] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param list<ClaimedDelivery> $claimed
     * @param list<string> $ids
     *
     * @return list<ClaimedDelivery>
     */
    private static function byId(array $claimed, array $ids): array
    {
        $byId = [];
        foreach ($claimed as $c) {
            $byId[$c->delivery->id] = $c;
        }

        return array_map(static function (string $id) use ($byId): ClaimedDelivery {
            self::assertArrayHasKey($id, $byId);

            return $byId[$id];
        }, $ids);
    }
}
