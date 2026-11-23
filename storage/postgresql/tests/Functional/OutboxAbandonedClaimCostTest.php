<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Freyr\MessageBroker\Outbox\ClaimOutcome;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxRetention;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxStore;
use PDO;

/**
 * The cost of abandoned rows for the claim. idx_outbox_pending keeps the
 * predicate published_at IS NULL, so an abandoned row stays in the index: it
 * has an old available_at, so each claim of its lane reads it and skips it.
 * The permanent assertion: a claim of 100 still returns the 100 due rows. The
 * test prints EXPLAIN (ANALYZE, BUFFERS) of the claim with no abandoned row and
 * with 1,000 to STDOUT, to record the scale. The table has the same rows in both
 * plans: the 1,000 old rows are first published (outside the index), then
 * abandoned (inside the index). 20,000 published rows make the partition look
 * like a live one, where the planner reads the index and not the whole table.
 */
final class OutboxAbandonedClaimCostTest extends FunctionalTestCase
{
    private const string LANE = 'claim_cost';

    private const string CLAIM = <<<'SQL'
        SELECT id, lane, message_name, body, headers, created_at, available_at, attempts
        FROM outbox_messages
        WHERE lane = 'claim_cost' AND published_at IS NULL AND abandoned_at IS NULL
          AND available_at <= now()::timestamp(3)
        ORDER BY available_at
        LIMIT 100
        FOR UPDATE SKIP LOCKED
        SQL;

    protected function setUp(): void
    {
        parent::setUp();
        // The rows have a created_at up to 20 s before now: in the first seconds of
        // a UTC day they belong to the day before. Day before, today and tomorrow.
        (new PostgreSqlOutboxRetention(self::$pdo))->ensureCapacity(2, (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('-1 day'));
    }

    public function testAClaimOf100ReturnsThe100DueRowsBehind1000AbandonedRows(): void
    {
        $yesterday = 'outbox_messages_p'.(new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('-1 day')->format('Ymd');
        self::assertSame(1, self::fetchInt(
            "SELECT COUNT(*) FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid
             WHERE i.inhparent = 'outbox_messages'::regclass AND c.relname = '{$yesterday}'",
        ), 'the partition of the day before exists for the rows of the first seconds of a UTC day');
        $this->insertRows('published', 20_000, "now() - interval '2 hour'");
        $this->insertRows('due', 100, "now() - interval '1 minute'");
        $this->insertRows('old', 1_000, "now() - interval '1 hour'"); // older: at the start of the index range
        self::$pdo->exec("UPDATE outbox_messages SET published_at = now() WHERE message_name IN ('old', 'published')");
        self::$pdo->exec('VACUUM ANALYZE outbox_messages');
        $withoutAbandoned = $this->explainClaim();

        self::$pdo->exec("UPDATE outbox_messages SET published_at = NULL, abandoned_at = now() WHERE message_name = 'old'");
        self::$pdo->exec('VACUUM ANALYZE outbox_messages');
        self::assertSame(1_000, self::fetchInt('SELECT COUNT(*) FROM outbox_messages WHERE abandoned_at IS NOT NULL AND published_at IS NULL'));
        $withAbandoned = $this->explainClaim();

        // STDOUT, not STDERR: Infection stops its initial test run at the first output on STDERR.
        print "\nClaim of 100, no abandoned row:\n{$withoutAbandoned}\nClaim of 100, 1,000 abandoned rows:\n{$withAbandoned}\n";

        $claimed = [];
        (new PostgreSqlOutboxStore(self::$pdo))->drainClaimed(self::LANE, 100, static function (array $records) use (&$claimed): ClaimOutcome {
            $claimed = $records;

            return ClaimOutcome::published([]);
        });

        self::assertCount(100, $claimed);
        foreach ($claimed as $record) {
            self::assertInstanceOf(OutboxRecord::class, $record);
            self::assertSame('due', $record->messageName, 'only due rows, no abandoned row');
        }
    }

    /** Rows of today's partition (created now), due since the given SQL time. */
    private function insertRows(string $name, int $count, string $availableAt): void
    {
        self::$pdo->exec(<<<SQL
            INSERT INTO outbox_messages (id, lane, message_name, body, headers, created_at, available_at, attempts)
            SELECT gen_random_uuid(), 'claim_cost', '{$name}', '\\x7b7d'::bytea, '{}'::jsonb,
                   now()::timestamp(3) - (g * interval '1 millisecond'), ({$availableAt})::timestamp(3) + (g * interval '1 millisecond'), 0
            FROM generate_series(1, {$count}) AS g
            SQL);
    }

    private function explainClaim(): string
    {
        self::$pdo->beginTransaction();

        try {
            $statement = self::$pdo->query('EXPLAIN (ANALYZE, BUFFERS) '.self::CLAIM);
            self::assertNotFalse($statement);

            /** @var list<string> $lines */
            $lines = $statement->fetchAll(PDO::FETCH_COLUMN);
        } finally {
            self::$pdo->rollBack(); // EXPLAIN ANALYZE takes the row locks: release them
        }

        return implode("\n", $lines);
    }
}
