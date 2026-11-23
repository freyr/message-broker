<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\Console\DedupCleanupCommand;
use Freyr\MessageBroker\Console\DlqListCommand;
use Freyr\MessageBroker\Console\DlqPurgeCommand;
use Freyr\MessageBroker\Console\DlqReplayCommand;
use Freyr\MessageBroker\Console\DlqShowCommand;
use Freyr\MessageBroker\Console\DlqStatsCommand;
use Freyr\MessageBroker\Console\SetupSchemaCommand;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\ReplayService;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Storage\PostgreSql\PdoTransaction;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeadLetterStore;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeduplicationStore;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxStore;
use Freyr\MessageBroker\Time\EpochMillis;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class ConsoleCommandsTest extends FunctionalTestCase
{
    private PostgreSqlDeadLetterStore $deadLetters;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deadLetters = new PostgreSqlDeadLetterStore(self::$pdo);
    }

    public function testSetupSchemaDumpSqlPrintsDdlWithoutTouchingTheDatabase(): void
    {
        $tester = new CommandTester(new SetupSchemaCommand(self::schemaInstaller()));

        $tester->execute([
            '--dump-sql' => true,
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS outbox_messages', $tester->getDisplay());
        self::assertStringContainsString('message_deduplication', $tester->getDisplay());
        self::assertStringContainsString('dead_letters', $tester->getDisplay());
    }

    public function testSetupSchemaExecutesDdl(): void
    {
        $tester = new CommandTester(new SetupSchemaCommand(self::schemaInstaller()));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $schemaPredicate = 'table_schema = current_schema()';
        self::assertSame(1, self::fetchInt(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE {$schemaPredicate} AND table_name = 'outbox_messages'",
        ));
        $today = 'outbox_messages_p'.gmdate('Ymd');
        self::assertSame(1, self::fetchInt(
            "SELECT COUNT(*) FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid
             WHERE i.inhparent = 'outbox_messages'::regclass AND c.relname = '{$today}'",
        ), 'setup creates the daily partition of today');
    }

    public function testDedupCleanupParsesDurationAndPrunes(): void
    {
        $store = new PostgreSqlDeduplicationStore(self::$pdo);
        $store->acquire(new IncomingMessage('m-old', 'order.placed', EpochMillis::now(), []), 'c');
        $eightDaysAgo = EpochMillis::toDateTime(EpochMillis::now() - 8 * 86_400_000)->format('Y-m-d H:i:s.v');
        self::$pdo->prepare('UPDATE message_deduplication SET created_at = :ts')->execute([
            'ts' => $eightDaysAgo,
        ]);
        $store->acquire(new IncomingMessage('m-new', 'order.placed', EpochMillis::now(), []), 'c');

        $tester = new CommandTester(new DedupCleanupCommand($store));
        $tester->execute([
            '--older-than' => '7d',
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1', $tester->getDisplay());
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM message_deduplication'));
    }

    public function testDlqListShowReplayPurgeRoundTrip(): void
    {
        $deadLetter = DeadLetter::fromFailure(
            source: 'orders_q',
            messageId: '01900000-0000-7000-8000-000000000001',
            messageName: 'order.placed',
            body: (string) json_encode([
                'metadata' => [
                    'message_id' => '01900000-0000-7000-8000-000000000001',
                    'message_name' => 'order.placed',
                    'created_at' => EpochMillis::now(),
                ],
                'payload' => [
                    'order_id' => 'o-1',
                ],
            ]),
            headers: [],
            error: new RuntimeException('boom'),
            attempts: 5,
        );
        $this->deadLetters->store($deadLetter);

        $list = new CommandTester(new DlqListCommand($this->deadLetters));
        $list->execute([]);
        $list->assertCommandIsSuccessful();
        self::assertStringContainsString('order.placed', $list->getDisplay());
        self::assertStringContainsString($deadLetter->id, $list->getDisplay());

        $show = new CommandTester(new DlqShowCommand($this->deadLetters));
        $show->execute([
            'id' => $deadLetter->id,
        ]);
        $show->assertCommandIsSuccessful();
        self::assertStringContainsString('boom', $show->getDisplay());

        $replay = new CommandTester(new DlqReplayCommand(
            new ReplayService($this->deadLetters, new PostgreSqlOutboxStore(
                self::$pdo,
            ), new PdoTransaction(self::$pdo), new JsonWireFormat()),
            $this->deadLetters,
        ));
        $replay->execute([
            'id' => $deadLetter->id,
            '--lane' => 'orders',
        ]);
        $replay->assertCommandIsSuccessful();
        self::assertSame(
            1,
            self::fetchInt("SELECT COUNT(*) FROM outbox_messages WHERE lane = 'orders' AND id = '01900000-0000-7000-8000-000000000001'"),
        );

        $purge = new CommandTester(new DlqPurgeCommand($this->deadLetters));
        $purge->execute([
            '--force' => true,
        ], [
            'interactive' => false,
        ]);
        $purge->assertCommandIsSuccessful();
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM dead_letters'));
    }

    public function testSetupSchemaDumpUsesByteaBody(): void
    {
        $tester = new CommandTester(new SetupSchemaCommand(self::schemaInstaller()));
        $tester->execute([
            '--dump-sql' => true,
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString(
            'body BYTEA',
            $tester->getDisplay(),
        );
    }

    public function testReplayAllFailsClosedWithoutForceOnNonTty(): void
    {
        $this->seedDeadLetter('01900000-0000-7000-8000-00000000000a', 'order.placed');

        $replay = new CommandTester(new DlqReplayCommand(
            new ReplayService($this->deadLetters, new PostgreSqlOutboxStore(
                self::$pdo,
            ), new PdoTransaction(self::$pdo), new JsonWireFormat()),
            $this->deadLetters,
        ));
        $status = $replay->execute([
            '--all' => true,
        ], [
            'interactive' => false,
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('--force', $replay->getDisplay());
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'));
    }

    public function testReplayAllDryRunPreviewsWithoutReplaying(): void
    {
        $this->seedDeadLetter('01900000-0000-7000-8000-00000000000b', 'order.placed');

        $replay = new CommandTester(new DlqReplayCommand(
            new ReplayService($this->deadLetters, new PostgreSqlOutboxStore(
                self::$pdo,
            ), new PdoTransaction(self::$pdo), new JsonWireFormat()),
            $this->deadLetters,
        ));
        $replay->execute([
            '--all' => true,
            '--dry-run' => true,
        ], [
            'interactive' => false,
        ]);

        $replay->assertCommandIsSuccessful();
        self::assertStringContainsString('1', $replay->getDisplay());
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'), 'dry-run changes nothing');
    }

    public function testReplayAllWithForceReplays(): void
    {
        $this->seedDeadLetter('01900000-0000-7000-8000-00000000000c', 'order.placed');

        $replay = new CommandTester(new DlqReplayCommand(
            new ReplayService($this->deadLetters, new PostgreSqlOutboxStore(
                self::$pdo,
            ), new PdoTransaction(self::$pdo), new JsonWireFormat()),
            $this->deadLetters,
        ));
        $replay->execute([
            '--all' => true,
            '--force' => true,
            '--lane' => 'orders',
        ], [
            'interactive' => false,
        ]);

        $replay->assertCommandIsSuccessful();
        self::assertSame(
            "Replayed 1, skipped 0 (not replayable), skipped 0 (already replayed), failed 0 into lane 'orders'.\n",
            $replay->getDisplay(),
        );
        self::assertSame(1, self::fetchInt("SELECT COUNT(*) FROM outbox_messages WHERE lane = 'orders'"));
    }

    public function testReplayAllReplaysTheGoodRowsAndSkipsARawBodyAmongThem(): void
    {
        $this->seedDeadLetter('01900000-0000-7000-8000-0000000000d1', 'order.placed');
        $raw = $this->seedDeadLetter('01900000-0000-7000-8000-0000000000d2', 'order.placed', body: 'raw bytes');
        $this->seedDeadLetter('01900000-0000-7000-8000-0000000000d3', 'order.placed');
        $this->seedDeadLetter('01900000-0000-7000-8000-0000000000d4', 'order.placed');

        $replay = new CommandTester(new DlqReplayCommand(
            new ReplayService($this->deadLetters, new PostgreSqlOutboxStore(
                self::$pdo,
            ), new PdoTransaction(self::$pdo), new JsonWireFormat()),
            $this->deadLetters,
            batchSize: 2,
        ));
        $status = $replay->execute([
            '--all' => true,
            '--force' => true,
            '--lane' => 'orders',
        ], [
            'interactive' => false,
        ]);

        self::assertSame(Command::SUCCESS, $status, 'a skip is not a failure');
        self::assertSame(
            "Skipped: Dead letter '{$raw->id}' is not replayable: body is not a replayable wire document "
            ."(stage-1 dead letters store raw bytes and are not replayable).\n"
            ."Replayed 3, skipped 1 (not replayable), skipped 0 (already replayed), failed 0 into lane 'orders'.\n",
            $replay->getDisplay(),
        );
        self::assertSame(3, self::fetchInt("SELECT COUNT(*) FROM outbox_messages WHERE lane = 'orders'"));
        self::assertSame(
            1,
            self::fetchInt("SELECT COUNT(*) FROM dead_letters WHERE replayed_at IS NULL AND message_id = '01900000-0000-7000-8000-0000000000d2'"),
            'the raw row stays open',
        );
    }

    public function testReplayOfAReplayedRowFailsAndInsertsNothing(): void
    {
        $deadLetter = $this->seedDeadLetter('01900000-0000-7000-8000-0000000000d5', 'order.placed');
        $this->deadLetters->markReplayed($deadLetter->id);

        $replay = new CommandTester(new DlqReplayCommand(
            new ReplayService($this->deadLetters, new PostgreSqlOutboxStore(
                self::$pdo,
            ), new PdoTransaction(self::$pdo), new JsonWireFormat()),
            $this->deadLetters,
        ));
        $status = $replay->execute([
            'id' => $deadLetter->id,
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame("Dead letter '{$deadLetter->id}' is already replayed.\n", $replay->getDisplay());
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'));
    }

    public function testPurgeFailsClosedWithoutForceButDryRunPreviews(): void
    {
        $this->seedDeadLetter('01900000-0000-7000-8000-0000000000e1', 'order.placed');
        $this->seedDeadLetter('01900000-0000-7000-8000-0000000000e2', 'order.cancelled');

        $purge = fn (): CommandTester => new CommandTester(new DlqPurgeCommand($this->deadLetters));

        $closed = $purge();
        self::assertSame(Command::FAILURE, $closed->execute([], [
            'interactive' => false,
        ]));
        self::assertSame(2, self::fetchInt('SELECT COUNT(*) FROM dead_letters'), 'nothing purged without --force');

        $dry = $purge();
        $dry->execute([
            '--dry-run' => true,
            '--name' => 'order.placed',
        ], [
            'interactive' => false,
        ]);
        $dry->assertCommandIsSuccessful();
        self::assertSame(2, self::fetchInt('SELECT COUNT(*) FROM dead_letters'), 'dry-run changes nothing');

        $forced = $purge();
        $forced->execute([
            '--force' => true,
            '--name' => 'order.placed',
        ], [
            'interactive' => false,
        ]);
        $forced->assertCommandIsSuccessful();
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM dead_letters'), 'only matching rows purged');
    }

    public function testDedupCleanupDryRunReportsWithoutDeleting(): void
    {
        $store = new PostgreSqlDeduplicationStore(self::$pdo);
        $store->acquire(new IncomingMessage('m-old', 'order.placed', EpochMillis::now(), []), 'c');
        $eightDaysAgo = EpochMillis::toDateTime(EpochMillis::now() - 8 * 86_400_000)->format('Y-m-d H:i:s.v');
        self::$pdo->prepare('UPDATE message_deduplication SET created_at = :ts')->execute([
            'ts' => $eightDaysAgo,
        ]);

        $tester = new CommandTester(new DedupCleanupCommand($store));
        $tester->execute([
            '--older-than' => '7d',
            '--dry-run' => true,
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1', $tester->getDisplay());
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM message_deduplication'), 'dry-run deletes nothing');
    }

    public function testDlqListRespectsOffset(): void
    {
        $this->seedDeadLetter('01900000-0000-7000-8000-000000000001', 'order.placed');
        $this->seedDeadLetter('01900000-0000-7000-8000-000000000002', 'order.placed');

        $list = new CommandTester(new DlqListCommand($this->deadLetters));
        $list->execute([
            '--limit' => '1',
            '--offset' => '1',
        ]);
        $list->assertCommandIsSuccessful();
        // exactly one data row rendered (header + 1)
        self::assertSame(1, substr_count($list->getDisplay(), 'order.placed'));
    }

    public function testReplayAllDrainsInBatchesAndSkipsAlreadyReplayed(): void
    {
        $this->seedDeadLetter('01900000-0000-7000-8000-000000000001', 'order.placed');
        $this->seedDeadLetter('01900000-0000-7000-8000-000000000002', 'order.placed');
        $alreadyReplayed = $this->seedDeadLetter('01900000-0000-7000-8000-000000000003', 'order.placed');
        $this->deadLetters->markReplayed($alreadyReplayed->id);

        // batchSize 1 forces the drain through multiple pages.
        $replay = new CommandTester(new DlqReplayCommand(
            new ReplayService($this->deadLetters, new PostgreSqlOutboxStore(
                self::$pdo,
            ), new PdoTransaction(self::$pdo), new JsonWireFormat()),
            $this->deadLetters,
            batchSize: 1,
        ));
        $replay->execute([
            '--all' => true,
            '--force' => true,
            '--lane' => 'orders',
        ], [
            'interactive' => false,
        ]);

        $replay->assertCommandIsSuccessful();
        self::assertSame(
            "Replayed 2, skipped 0 (not replayable), skipped 0 (already replayed), failed 0 into lane 'orders'.\n",
            $replay->getDisplay(),
        );
        self::assertSame(2, self::fetchInt("SELECT COUNT(*) FROM outbox_messages WHERE lane = 'orders'"));
        self::assertSame(
            0,
            self::fetchInt("SELECT COUNT(*) FROM outbox_messages WHERE id = '01900000-0000-7000-8000-000000000003'"),
            'an already-replayed dead letter must not be replayed again',
        );
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM dead_letters WHERE replayed_at IS NULL'));
    }

    public function testDlqStatsGroupsTheRowsOfTheTable(): void
    {
        $this->seedDeadLetter('01900000-0000-7000-8000-0000000000a1', 'order.placed');
        $this->seedDeadLetter('01900000-0000-7000-8000-0000000000a2', 'order.placed');
        $replayed = $this->seedDeadLetter('01900000-0000-7000-8000-0000000000a3', 'order.cancelled');
        $this->deadLetters->markReplayed($replayed->id);

        $all = new CommandTester(new DlqStatsCommand($this->deadLetters));
        $all->execute([]);
        $all->assertCommandIsSuccessful();
        $lines = explode("\n", $all->getDisplay());
        self::assertMatchesRegularExpression('/^\| order\.placed\s+\| orders_q \| RuntimeException \| 2\s+\| /', $lines[3]);
        self::assertMatchesRegularExpression('/^\| order\.cancelled \| orders_q \| RuntimeException \| 1\s+\| /', $lines[4]);

        $open = new CommandTester(new DlqStatsCommand($this->deadLetters));
        $open->execute([
            '--not-replayed' => true,
        ]);
        $open->assertCommandIsSuccessful();
        self::assertStringContainsString('order.placed', $open->getDisplay());
        self::assertStringNotContainsString('order.cancelled', $open->getDisplay());

        $none = new CommandTester(new DlqStatsCommand($this->deadLetters));
        $none->execute([
            '--source' => 'unknown_q',
        ]);
        self::assertSame("No dead letters match.\n", $none->getDisplay());
    }

    public function testDlqPurgeDryRunWithOlderThanGivesTheExactCount(): void
    {
        $old = $this->seedDeadLetter('01900000-0000-7000-8000-0000000000b1', 'order.placed');
        $this->seedDeadLetter('01900000-0000-7000-8000-0000000000b2', 'order.placed');
        self::$pdo->prepare('UPDATE dead_letters SET failed_at = :failed_at WHERE id = :id')->execute([
            'failed_at' => EpochMillis::toDateTime(EpochMillis::now() - 31 * 86_400_000)->format('Y-m-d H:i:s.v'),
            'id' => $old->id,
        ]);

        $dry = new CommandTester(new DlqPurgeCommand($this->deadLetters));
        $dry->execute([
            '--dry-run' => true,
            '--older-than' => '30d',
        ], [
            'interactive' => false,
        ]);

        $dry->assertCommandIsSuccessful();
        self::assertSame("[dry-run] would delete 1 dead letters.\n", $dry->getDisplay());
        self::assertSame(2, self::fetchInt('SELECT COUNT(*) FROM dead_letters'));
    }

    /**
     * A dead letter that failed one second ago: a bulk replay reads only the
     * rows that failed before the start of its run.
     */
    private function seedDeadLetter(string $id, string $name, ?string $body = null): DeadLetter
    {
        $deadLetter = new DeadLetter(
            id: Uuid::v7()->toString(),
            source: 'orders_q',
            messageId: $id,
            messageName: $name,
            body: $body ?? (string) json_encode([
                'metadata' => [
                    'message_id' => $id,
                    'message_name' => $name,
                    'created_at' => EpochMillis::now(),
                ],
                'payload' => [
                    'order_id' => 'o-1',
                ],
            ]),
            headers: [],
            errorClass: RuntimeException::class,
            errorMessage: 'boom',
            errorTrace: '#0 trace',
            attempts: 5,
            failedAt: EpochMillis::now() - 1_000,
        );
        $this->deadLetters->store($deadLetter);

        return $deadLetter;
    }
}
