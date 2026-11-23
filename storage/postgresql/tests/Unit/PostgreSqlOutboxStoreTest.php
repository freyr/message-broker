<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Unit;

use Freyr\MessageBroker\Outbox\ClaimOutcome;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxStore;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** SQL text and body handling of the PostgreSQL outbox store, without a database. */
final class PostgreSqlOutboxStoreTest extends TestCase
{
    public function testClaimQuerySkipsLockedUnpublishedDueRows(): void
    {
        $sql = [];
        $store = new PostgreSqlOutboxStore($this->pdoReturning([], $sql));

        $store->drainClaimed('lane', 10, static fn (array $claimed): ClaimOutcome => ClaimOutcome::published([]));

        self::assertCount(1, $sql);
        self::assertStringContainsString('WHERE lane = :lane AND published_at IS NULL AND abandoned_at IS NULL AND available_at <= :now', $sql[0]);
        self::assertStringContainsString('ORDER BY available_at', $sql[0]);
        self::assertStringContainsString('LIMIT :limit', $sql[0]);
        self::assertStringContainsString('FOR UPDATE SKIP LOCKED', $sql[0]);
    }

    public function testAbandonOfAValueThatIsNotAUuidSendsNoQuery(): void
    {
        $sql = [];
        $store = new PostgreSqlOutboxStore($this->pdoReturning([], $sql));

        self::assertSame(0, $store->abandon('not-a-uuid'));
        self::assertSame(0, $store->abandon('x01900000-0000-7000-8000-000000000001'));
        self::assertSame(0, $store->abandon('01900000-0000-7000-8000-000000000001x'));
        self::assertSame(0, $store->abandon("01900000-0000-7000-8000-000000000001\n"), 'a trailing newline');
        self::assertSame([], $sql);
    }

    public function testInsertSqlTargetsTheOutboxTableWithZeroAttempts(): void
    {
        $sql = [];
        $store = new PostgreSqlOutboxStore($this->pdoReturning([], $sql));

        $store->insert(new OutboxRecord('id-1', 'lane', 'order.placed', '{}', [], 1_700_000_000_000));

        self::assertStringContainsString('INSERT INTO outbox_messages', $sql[0]);
        self::assertStringContainsString('(:id, :lane, :message_name, :body, :headers, :created_at, :available_at, 0)', $sql[0]);
    }

    public function testBodyStreamIsReadIntoAString(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, "\x00\x01binary");
        rewind($stream);

        $records = $this->claim($this->row($stream));

        self::assertSame("\x00\x01binary", $records[0]->body);
    }

    public function testBodyStringPassesThrough(): void
    {
        $records = $this->claim($this->row('{"a":1}'));

        self::assertSame('{"a":1}', $records[0]->body);
    }

    public function testBodyThatIsNeitherStreamNorStringFailsTheClaim(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Body column is not a string or stream');

        $this->claim($this->row(42));
    }

    /** @return list<OutboxRecord> */
    private function claim(array $row): array
    {
        $sql = [];
        $store = new PostgreSqlOutboxStore($this->pdoReturning([$row], $sql));
        $claimed = [];
        $store->drainClaimed('lane', 10, static function (array $records) use (&$claimed): ClaimOutcome {
            $claimed = $records;

            return ClaimOutcome::published([]);
        });

        return $claimed;
    }

    /** @return array<string, mixed> */
    private function row(mixed $body): array
    {
        return [
            'id' => '01900000-0000-7000-8000-000000000001',
            'lane' => 'lane',
            'message_name' => 'order.placed',
            'body' => $body,
            'headers' => '{}',
            'created_at' => '2026-03-03 10:00:00.123',
            'available_at' => '2026-03-03 10:00:00.123',
            'attempts' => 0,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $sql the prepared statements, in order
     */
    private function pdoReturning(array $rows, array &$sql): PDO
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetchAll')->willReturn($rows);

        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(static function (string $query) use (&$sql, $statement): PDOStatement {
            $sql[] = $query;

            return $statement;
        });

        return $pdo;
    }
}
