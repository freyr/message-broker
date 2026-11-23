<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\Outbox\ClaimOutcome;
use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxStore;
use Freyr\MessageBroker\Testing\OrderPlaced;
use Freyr\MessageBroker\Time\EpochMillis;
use PDO;

/**
 * Store-level contract of the drain: claims are transactional, disjoint
 * across connections, non-blocking, and crash-safe (a dead claimer's rows are
 * instantly reclaimable). Published rows stay, with published_at set.
 */
final class OutboxClaimTest extends FunctionalTestCase
{
    private const string LANE = 'claims';

    private PostgreSqlOutboxStore $store;
    private OutboxProducer $producer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new PostgreSqlOutboxStore(self::$pdo);
        $this->producer = new OutboxProducer($this->store, new JsonWireFormat(), lane: self::LANE);
    }

    /** @return list<string> ids of the claimed records */
    private static function ids(array $claimed): array
    {
        return array_map(static fn (OutboxRecord $record): string => $record->id, $claimed);
    }

    /** Hold a raw claim of $limit rows open on a separate connection. */
    private function rivalClaim(PDO $rival, int $limit): array
    {
        $rival->beginTransaction();
        $statement = $rival->prepare(<<<'SQL'
            SELECT id, lane, message_name, body, headers, created_at, available_at, attempts
            FROM outbox_messages
            WHERE lane = :lane AND published_at IS NULL AND available_at <= :now
            ORDER BY available_at
            LIMIT :limit
            FOR UPDATE SKIP LOCKED
            SQL);
        $statement->bindValue('lane', self::LANE);
        $statement->bindValue('now', EpochMillis::toDateTime(EpochMillis::now())->format('Y-m-d H:i:s.v'));
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testPublishedOutcomeMarksRowsPublishedAndKeepsThem(): void
    {
        $this->producer->produce(OrderPlaced::create('o-1', 100));
        $this->producer->produce(OrderPlaced::create('o-2', 200));

        $published = $this->store->drainClaimed(
            self::LANE,
            10,
            static fn (array $claimed): ClaimOutcome => ClaimOutcome::published(self::ids($claimed)),
        );

        self::assertSame(2, $published);
        self::assertSame(2, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'), 'rows stay for retention');
        self::assertSame(2, self::fetchInt('SELECT COUNT(*) FROM outbox_messages WHERE published_at IS NOT NULL'));

        // A published row is never claimed again.
        $again = $this->store->drainClaimed(
            self::LANE,
            10,
            static fn (array $claimed): ClaimOutcome => ClaimOutcome::published(self::ids($claimed)),
        );
        self::assertSame(0, $again);
    }

    public function testPublishingAReplayedIdLeavesTheOriginalRowUntouched(): void
    {
        // A replay reuses the original message id; the original row (already
        // published) must keep its own published_at.
        $message = OrderPlaced::create('o-1', 100);
        $this->producer->produce($message);
        $this->store->drainClaimed(
            self::LANE,
            10,
            static fn (array $claimed): ClaimOutcome => ClaimOutcome::published(self::ids($claimed)),
        );
        self::$pdo->exec("UPDATE outbox_messages SET published_at = '2000-01-01 00:00:00'");

        $this->store->insert(new OutboxRecord(
            id: $message->id,
            lane: self::LANE,
            messageName: 'order.placed',
            body: '{}',
            headers: [],
            createdAt: $message->createdAt - 1_000, // distinct primary key, already due
        ));
        $published = $this->store->drainClaimed(
            self::LANE,
            10,
            static fn (array $claimed): ClaimOutcome => ClaimOutcome::published(self::ids($claimed)),
        );

        self::assertSame(1, $published);
        self::assertSame(1, self::fetchInt(
            "SELECT COUNT(*) FROM outbox_messages WHERE published_at = '2000-01-01 00:00:00'",
        ));
    }

    public function testRetryOutcomeBacksOffClaimedRowsIndividually(): void
    {
        $this->producer->produce(OrderPlaced::create('o-1', 100));
        $retryAt = EpochMillis::now() + 60_000;

        $published = $this->store->drainClaimed(
            self::LANE,
            10,
            static fn (array $claimed): ClaimOutcome => ClaimOutcome::retryAll(
                array_fill_keys(self::ids($claimed), $retryAt),
            ),
        );

        self::assertSame(0, $published);
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'));
        self::assertSame(1, self::fetchInt('SELECT attempts FROM outbox_messages'));
        // The backed-off row is no longer eligible: a second drain claims nothing.
        $rounds = $this->store->drainClaimed(
            self::LANE,
            10,
            static fn (array $claimed): ClaimOutcome => ClaimOutcome::published(self::ids($claimed)),
        );
        self::assertSame(0, $rounds);
    }

    public function testConcurrentClaimsAreDisjointAndNonBlocking(): void
    {
        $this->producer->produce(OrderPlaced::create('o-1', 100));
        $this->producer->produce(OrderPlaced::create('o-2', 200));
        $this->producer->produce(OrderPlaced::create('o-3', 300));

        $rival = self::newConnection();
        $rivalRows = $this->rivalClaim($rival, 1); // rival holds row 1 locked
        self::assertCount(1, $rivalRows);

        $seen = [];
        // A SKIP LOCKED regression would block on the rival's lock; fail fast
        // with a lock-wait error instead of hanging the suite.
        self::$pdo->exec("SET lock_timeout = '2s'");
        $published = $this->store->drainClaimed(self::LANE, 10, function (array $claimed) use (&$seen): ClaimOutcome {
            $seen = self::ids($claimed);

            return ClaimOutcome::published($seen);
        });

        self::assertSame(2, $published, 'must skip the locked row, not wait for it');
        self::assertNotContains($rivalRows[0]['id'], $seen);
        $rival->rollBack();
    }

    public function testDeadClaimerRowsAreInstantlyReclaimable(): void
    {
        $this->producer->produce(OrderPlaced::create('o-1', 100));

        $rival = self::newConnection();
        self::assertCount(1, $this->rivalClaim($rival, 1));
        $rival = null; // connection dies with its claim open — locks release

        $published = $this->store->drainClaimed(
            self::LANE,
            10,
            static fn (array $claimed): ClaimOutcome => ClaimOutcome::published(self::ids($claimed)),
        );

        self::assertSame(1, $published, 'a dead claimer must not strand its rows');
    }

    public function testProducerInsertIsNotBlockedByAnOpenClaim(): void
    {
        // An open claim's FOR UPDATE locks only the claimed rows: a producer
        // INSERT into the same lane must not wait for the claim to commit.
        $this->producer->produce(OrderPlaced::create('o-1', 100));

        $inserter = self::newConnection();
        $inserter->exec("SET lock_timeout = '2s'");
        $insertingProducer = new OutboxProducer(
            new PostgreSqlOutboxStore($inserter),
            new JsonWireFormat(),
            lane: self::LANE,
        );

        $published = $this->store->drainClaimed(
            self::LANE,
            10,
            static function (array $claimed) use ($insertingProducer): ClaimOutcome {
                // While the claim transaction is open: a producer INSERT into
                // the same lane must complete, not block. Throws on timeout.
                $insertingProducer->produce(OrderPlaced::create('o-2', 200));

                return ClaimOutcome::published(self::ids($claimed));
            },
        );

        self::assertSame(1, $published);
        self::assertSame(
            1,
            self::fetchInt('SELECT COUNT(*) FROM outbox_messages WHERE published_at IS NULL'),
            'the new row awaits the next pass',
        );
    }

    public function testTheClaimSkipsAnAbandonedRow(): void
    {
        $this->producer->produce($abandoned = OrderPlaced::create('o-1', 100));
        $this->producer->produce($open = OrderPlaced::create('o-2', 200));
        self::assertSame(1, $this->store->abandon($abandoned->id));

        $seen = [];
        $published = $this->store->drainClaimed(self::LANE, 10, static function (array $claimed) use (&$seen): ClaimOutcome {
            $seen = self::ids($claimed);

            return ClaimOutcome::published($seen);
        });

        self::assertSame(1, $published);
        self::assertSame([$open->id], $seen);
        self::assertSame(1, self::fetchInt(
            "SELECT COUNT(*) FROM outbox_messages WHERE id = '{$abandoned->id}' AND published_at IS NULL AND abandoned_at IS NOT NULL",
        ));
    }

    public function testAbandonOnASecondConnectionWaitsForAnOpenClaimOfTheRow(): void
    {
        $this->producer->produce($message = OrderPlaced::create('o-1', 100));
        $operator = self::newConnection();
        $operator->exec("SET lock_timeout = '100ms'");
        $operatorStore = new PostgreSqlOutboxStore($operator);

        $error = null;
        $this->store->drainClaimed(self::LANE, 10, static function (array $claimed) use ($operatorStore, &$error): ClaimOutcome {
            try {
                $operatorStore->abandon($claimed[0]->id); // the claim holds the row lock
            } catch (\PDOException $caught) {
                $error = $caught;
            }

            return ClaimOutcome::published(self::ids($claimed));
        });

        self::assertInstanceOf(\PDOException::class, $error, 'abandon() waits for the claim');
        self::assertSame('55P03', $error->getCode(), 'lock_not_available: the lock timeout');
        self::assertSame(0, $operatorStore->abandon($message->id), 'after the claim published the row, nothing to abandon');
        self::assertSame(1, self::fetchInt(
            "SELECT COUNT(*) FROM outbox_messages WHERE id = '{$message->id}' AND published_at IS NOT NULL AND abandoned_at IS NULL",
        ));
    }

    public function testAnOutcomeDoesNotChangeARowThatTheClaimTransactionAbandoned(): void
    {
        // On the connection of the claim, abandon() joins the claim transaction:
        // the guards of markPublished() and scheduleRetry() keep the row as it is.
        $this->producer->produce($published = OrderPlaced::create('o-1', 100));
        $this->producer->produce($retried = OrderPlaced::create('o-2', 200));

        $this->store->drainClaimed(self::LANE, 10, function () use ($published, $retried): ClaimOutcome {
            self::assertSame(1, $this->store->abandon($published->id));
            self::assertSame(1, $this->store->abandon($retried->id));

            return new ClaimOutcome([$published->id], [
                $retried->id => EpochMillis::now() + 60_000,
            ]);
        });

        self::assertSame(2, self::fetchInt(
            'SELECT COUNT(*) FROM outbox_messages WHERE published_at IS NULL AND abandoned_at IS NOT NULL AND attempts = 0',
        ));
    }

    public function testAbandonOfAValueThatIsNotAUuidReturnsZeroAndSendsNoQuery(): void
    {
        $id = '01900000-0000-7000-8000-00000000abcd';
        $this->store->insert(new OutboxRecord($id, self::LANE, 'order.placed', '{}', [], EpochMillis::now()));

        // A query with such a value fails in PostgreSQL (invalid input syntax for type uuid).
        self::assertSame(0, $this->store->abandon('not-a-uuid'));
        self::assertSame(0, $this->store->abandon('x'.$id));
        self::assertSame(0, $this->store->abandon($id.'x'));
        self::assertSame(0, $this->store->abandon(''));
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM outbox_messages WHERE abandoned_at IS NOT NULL'));

        self::assertSame(1, $this->store->abandon(strtoupper($id)), 'a UUID in upper case is a UUID');
        self::assertSame(1, self::fetchInt("SELECT COUNT(*) FROM outbox_messages WHERE id = '{$id}' AND abandoned_at IS NOT NULL"));
    }

    public function testAbandonSetsAbandonedAtToNowAndLeavesTheOtherColumns(): void
    {
        $id = '01900000-0000-7000-8000-00000000abce';
        $createdAt = EpochMillis::now() - 2_000;
        $this->store->insert(new OutboxRecord($id, self::LANE, 'order.placed', '{}', [], $createdAt));
        $before = EpochMillis::now();

        self::assertSame(1, $this->store->abandon($id));

        $abandonedAt = self::fetchInt("SELECT (EXTRACT(EPOCH FROM abandoned_at) * 1000)::bigint FROM outbox_messages WHERE id = '{$id}'");
        self::assertGreaterThanOrEqual($before, $abandonedAt);
        self::assertLessThanOrEqual(EpochMillis::now(), $abandonedAt);
        self::assertSame(0, self::fetchInt("SELECT attempts FROM outbox_messages WHERE id = '{$id}'"));
        self::assertSame(1, self::fetchInt("SELECT COUNT(*) FROM outbox_messages WHERE id = '{$id}' AND published_at IS NULL"));
    }

    public function testInsertStoresTheGivenAvailableAtAndDefaultsItToCreatedAt(): void
    {
        $createdAt = EpochMillis::now();
        $later = $createdAt + 90_000;
        $this->store->insert(new OutboxRecord('01900000-0000-7000-8000-000000000001', self::LANE, 'order.placed', '{}', [], $createdAt, availableAt: $later));
        $this->store->insert(new OutboxRecord('01900000-0000-7000-8000-000000000002', self::LANE, 'order.placed', '{}', [], $createdAt));

        $availableAt = static fn (string $id): int => self::fetchInt(
            "SELECT (EXTRACT(EPOCH FROM available_at) * 1000)::bigint FROM outbox_messages WHERE id = '{$id}'",
        );
        self::assertSame($later, $availableAt('01900000-0000-7000-8000-000000000001'));
        self::assertSame($createdAt, $availableAt('01900000-0000-7000-8000-000000000002'));
    }

    public function testRetryOutcomeBacksOffEachRowAtItsOwnTimeAndCountsTheAttempt(): void
    {
        $this->producer->produce(OrderPlaced::create('o-1', 100));
        $this->producer->produce(OrderPlaced::create('o-2', 200));
        $base = EpochMillis::now() + 60_000;
        $retryAt = [];

        $this->store->drainClaimed(
            self::LANE,
            10,
            static function (array $claimed) use (&$retryAt, $base): ClaimOutcome {
                foreach (self::ids($claimed) as $position => $id) {
                    $retryAt[$id] = $base + $position * 10_000;
                }

                return ClaimOutcome::retryAll($retryAt);
            },
        );

        self::assertCount(2, $retryAt);
        foreach ($retryAt as $id => $expected) {
            self::assertSame($expected, self::fetchInt(
                "SELECT (EXTRACT(EPOCH FROM available_at) * 1000)::bigint FROM outbox_messages WHERE id = '{$id}'",
            ));
            self::assertSame(1, self::fetchInt("SELECT attempts FROM outbox_messages WHERE id = '{$id}'"));
        }
    }
}
