<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeadLetterStore;
use PDO;
use PDOException;

/**
 * The migration note of UPGRADE.md (0.10.0) on a dead_letters table of 0.9.x:
 * no replayable column, no uq_dead_letters_open index, duplicate open rows of
 * one pair and rows with the literal message id 'unknown'. The statements are
 * the statements of the note; this test keeps the note and the code equal.
 */
final class DeadLetterStoreUpgradeTest extends FunctionalTestCase
{
    private const string OLD_TABLE = <<<'SQL'
        CREATE TABLE dead_letters (
            id UUID NOT NULL PRIMARY KEY,
            source VARCHAR(255) NOT NULL,
            message_id VARCHAR(255) NOT NULL,
            message_name VARCHAR(255) NOT NULL,
            body BYTEA NOT NULL,
            headers JSONB NOT NULL,
            error_class VARCHAR(255) NOT NULL,
            error_message TEXT NOT NULL,
            error_trace TEXT NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            failed_at TIMESTAMP(3) NOT NULL,
            replayed_at TIMESTAMP(3) NULL
        )
        SQL;

    private const string STEP_1 = 'ALTER TABLE dead_letters ADD COLUMN replayable BOOLEAN NOT NULL DEFAULT TRUE';

    private const string STEP_2 = "UPDATE dead_letters SET message_id = 'unknown-' || id WHERE message_id = 'unknown'";

    private const string COUNT_DUPLICATES = <<<'SQL'
        SELECT message_id, source, COUNT(*) FROM dead_letters
        WHERE replayed_at IS NULL
        GROUP BY message_id, source
        HAVING COUNT(*) > 1
        SQL;

    private const string STEP_3 = <<<'SQL'
        DELETE FROM dead_letters d
        USING dead_letters n
        WHERE d.replayed_at IS NULL AND n.replayed_at IS NULL
          AND d.message_id = n.message_id AND d.source = n.source
          AND (n.failed_at, n.id) > (d.failed_at, d.id)
        SQL;

    private const string STEP_4 = 'CREATE UNIQUE INDEX CONCURRENTLY uq_dead_letters_open ON dead_letters (message_id, source) WHERE replayed_at IS NULL';

    private const string DROP_INVALID_INDEX = 'DROP INDEX CONCURRENTLY IF EXISTS uq_dead_letters_open';

    private const string DUPLICATE_OLD = '01900000-0000-7000-8000-000000000d01';
    private const string DUPLICATE_NEW = '01900000-0000-7000-8000-000000000d02';
    private const string DUPLICATE_REPLAYED = '01900000-0000-7000-8000-000000000d03';
    private const string UNKNOWN_A = '01900000-0000-7000-8000-000000000e01';
    private const string UNKNOWN_B = '01900000-0000-7000-8000-000000000e02';

    public static function tearDownAfterClass(): void
    {
        // The next test class installs the current schema again (setUpBeforeClass).
        self::$pdo->exec('DROP TABLE IF EXISTS dead_letters');
    }

    public function testTheStepsOfTheNoteKeepTheNewestOpenRowOfEachPairAndGiveEachUnknownRowItsOwnId(): void
    {
        $this->installTheOldTable();
        $this->insertOld(self::DUPLICATE_REPLAYED, 'm-dup', '2026-06-01 10:00:00', replayedAt: '2026-06-01 11:00:00');
        $this->insertOld(self::DUPLICATE_OLD, 'm-dup', '2026-06-02 10:00:00');
        $this->insertOld(self::DUPLICATE_NEW, 'm-dup', '2026-06-03 10:00:00');
        $this->insertOld(self::UNKNOWN_A, 'unknown', '2026-06-02 10:00:00');
        $this->insertOld(self::UNKNOWN_B, 'unknown', '2026-06-03 10:00:00');

        // Steps 1 to 3 run in one transaction.
        self::$pdo->beginTransaction();
        self::$pdo->exec(self::STEP_1);
        self::$pdo->exec(self::STEP_2);
        self::assertSame([[
            'message_id' => 'm-dup',
            'source' => 'orders_q',
            'count' => 2,
        ]], $this->rows(self::COUNT_DUPLICATES), 'the count of the note shows the duplicates before the delete');
        self::$pdo->exec(self::STEP_3);
        self::$pdo->commit();
        self::assertSame([], $this->rows(self::COUNT_DUPLICATES));

        // Step 4 cannot run inside a transaction block.
        self::$pdo->beginTransaction();
        try {
            self::$pdo->exec(self::STEP_4);
            self::fail('CREATE INDEX CONCURRENTLY must refuse a transaction block');
        } catch (PDOException $error) {
            self::assertStringContainsString('cannot run inside a transaction block', $error->getMessage());
        } finally {
            self::$pdo->rollBack();
        }
        self::$pdo->exec(self::STEP_4);

        self::assertSame([
            [
                'id' => self::DUPLICATE_NEW,
                'message_id' => 'm-dup',
                'replayed' => false,
                'replayable' => true,
            ],
            [
                'id' => self::DUPLICATE_REPLAYED,
                'message_id' => 'm-dup',
                'replayed' => true,
                'replayable' => true,
            ],
            [
                'id' => self::UNKNOWN_A,
                'message_id' => 'unknown-'.self::UNKNOWN_A,
                'replayed' => false,
                'replayable' => true,
            ],
            [
                'id' => self::UNKNOWN_B,
                'message_id' => 'unknown-'.self::UNKNOWN_B,
                'replayed' => false,
                'replayable' => true,
            ],
        ], $this->table(), 'ordered by id');
        self::assertTrue($this->indexIsValid());

        // Step 5: the new code works on the migrated table.
        $store = new PostgreSqlDeadLetterStore(self::$pdo);
        $store->store(new DeadLetter(
            id: '01900000-0000-7000-8000-000000000f01',
            source: 'orders_q',
            messageId: 'm-dup',
            messageName: 'order.placed',
            body: '{}',
            headers: [],
            errorClass: 'RuntimeException',
            errorMessage: 'after the upgrade',
            errorTrace: '#0',
            attempts: 2,
            failedAt: 1_781_000_000_000,
        ));
        self::assertSame('after the upgrade', $store->find(self::DUPLICATE_NEW)?->errorMessage, 'the upsert updates the open row');
        self::assertSame(4, $store->count(new DeadLetterFilter()));
    }

    public function testAFailedIndexBuildIsDroppedAndTheRepeatFromStepTwoMakesTheIndexValid(): void
    {
        $this->installTheOldTable();
        $this->insertOld(self::DUPLICATE_OLD, 'm-dup', '2026-06-02 10:00:00');
        $this->insertOld(self::UNKNOWN_A, 'unknown', '2026-06-02 10:00:00');
        self::$pdo->exec(self::STEP_1);
        self::$pdo->exec(self::STEP_2);
        self::$pdo->exec(self::STEP_3);
        // The old code is still in service: a second open row of a pair, and a new literal 'unknown'.
        $this->insertOld(self::DUPLICATE_NEW, 'm-dup', '2026-06-03 10:00:00');
        $this->insertOld(self::UNKNOWN_B, 'unknown', '2026-06-03 10:00:00');

        try {
            self::$pdo->exec(self::STEP_4);
            self::fail('the build must fail on the duplicate open rows');
        } catch (PDOException $error) {
            self::assertStringContainsString('could not create unique index "uq_dead_letters_open"', $error->getMessage());
        }
        self::assertFalse($this->indexIsValid(), 'the failed build leaves an invalid index');

        self::$pdo->exec(self::DROP_INVALID_INDEX);
        self::assertNull($this->indexIsValid());
        self::$pdo->exec(self::STEP_2);
        self::$pdo->exec(self::STEP_3);
        self::$pdo->exec(self::STEP_4);

        self::assertTrue($this->indexIsValid());
        self::assertSame([
            [
                'id' => self::DUPLICATE_NEW,
                'message_id' => 'm-dup',
                'replayed' => false,
                'replayable' => true,
            ],
            [
                'id' => self::UNKNOWN_A,
                'message_id' => 'unknown-'.self::UNKNOWN_A,
                'replayed' => false,
                'replayable' => true,
            ],
            [
                'id' => self::UNKNOWN_B,
                'message_id' => 'unknown-'.self::UNKNOWN_B,
                'replayed' => false,
                'replayable' => true,
            ],
        ], $this->table(), 'the new unknown row stays as its own row');
    }

    private function installTheOldTable(): void
    {
        self::$pdo->exec('DROP TABLE IF EXISTS dead_letters');
        self::$pdo->exec(self::OLD_TABLE);
    }

    /** A row as the code of 0.9.x writes it: a plain INSERT with no replayable column. */
    private function insertOld(string $id, string $messageId, string $failedAt, ?string $replayedAt = null): void
    {
        $statement = self::$pdo->prepare(<<<'SQL'
            INSERT INTO dead_letters
                (id, source, message_id, message_name, body, headers, error_class, error_message, error_trace,
                 attempts, failed_at, replayed_at)
            VALUES (:id, 'orders_q', :message_id, 'order.placed', '\x7b7d', '{}', 'RuntimeException', 'boom', '#0',
                    1, :failed_at, :replayed_at)
            SQL);
        $statement->execute([
            'id' => $id,
            'message_id' => $messageId,
            'failed_at' => $failedAt,
            'replayed_at' => $replayedAt,
        ]);
    }

    /** @return list<array{id: string, message_id: string, replayed: bool, replayable: bool}> */
    private function table(): array
    {
        /** @var list<array{id: string, message_id: string, replayed: bool, replayable: bool}> $rows */
        $rows = $this->rows(
            'SELECT id, message_id, replayed_at IS NOT NULL AS replayed, replayable FROM dead_letters ORDER BY id',
        );

        return $rows;
    }

    /** @return bool|null the indisvalid flag of uq_dead_letters_open, null when the index does not exist */
    private function indexIsValid(): ?bool
    {
        $rows = $this->rows(
            "SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = 'uq_dead_letters_open'",
        );

        return $rows === [] ? null : (bool) $rows[0]['indisvalid'];
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $statement = self::$pdo->query($sql);
        self::assertNotFalse($statement);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }
}
