<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\MessageBroker\Outbox\ClaimOutcome;
use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\PdoOutboxStore;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Tests\Fixtures\OrderPlaced;
use Freyr\MessageBroker\Time\EpochMillis;
use PDO;

/**
 * Store-level contract of the competing drain (spec D-C3): claims are
 * transactional, disjoint across connections, non-blocking, and crash-safe
 * (a dead claimer's rows are instantly reclaimable).
 */
final class OutboxClaimTest extends FunctionalTestCase
{
    private const string LANE = 'claims';

    private PdoOutboxStore $store;
    private OutboxProducer $producer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new PdoOutboxStore(self::$pdo, static::platform());
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
        $isolation = static::platform()->claimIsolationSql();
        if ($isolation !== null) {
            $rival->exec($isolation);
        }
        $rival->beginTransaction();
        $statement = $rival->prepare(static::platform()->selectClaimBatchSql());
        $statement->bindValue('lane', self::LANE);
        $statement->bindValue('now', EpochMillis::toDateTime(EpochMillis::now())->format('Y-m-d H:i:s.v'));
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testPublishedOutcomeDeletesClaimedRows(): void
    {
        $this->producer->produce(OrderPlaced::create('o-1', 100));
        $this->producer->produce(OrderPlaced::create('o-2', 200));

        $published = $this->store->drainClaimed(
            self::LANE,
            10,
            static fn (array $claimed): ClaimOutcome => ClaimOutcome::published(self::ids($claimed)),
        );

        self::assertSame(2, $published);
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'));
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

    public function testEmptyClaimReturnsZeroWithoutInvokingCallback(): void
    {
        $invoked = false;
        $published = $this->store->drainClaimed(self::LANE, 10, function (array $claimed) use (
            &$invoked
        ): ClaimOutcome {
            $invoked = true;

            return ClaimOutcome::published([]);
        });

        self::assertSame(0, $published);
        self::assertFalse($invoked, 'callback must not run for an empty claim');
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
        self::$pdo->exec(self::isPostgres() ? "SET lock_timeout = '2s'" : 'SET SESSION innodb_lock_wait_timeout = 2');
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
        // The MySQL gap-lock regression (spec: claim runs at READ COMMITTED).
        // Under REPEATABLE READ the open claim's FOR UPDATE would gap-lock the
        // lane range and this INSERT would hit lock-wait timeout.
        $this->producer->produce(OrderPlaced::create('o-1', 100));

        $inserter = self::newConnection();
        if (!self::isPostgres()) {
            $inserter->exec('SET SESSION innodb_lock_wait_timeout = 2');
        }
        $insertingProducer = new OutboxProducer(
            new PdoOutboxStore($inserter, static::platform()),
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
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'), 'the new row awaits the next pass');
    }
}
