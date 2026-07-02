<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\MessageBroker\Console\DedupCleanupCommand;
use Freyr\MessageBroker\Console\DlqListCommand;
use Freyr\MessageBroker\Console\DlqPurgeCommand;
use Freyr\MessageBroker\Console\DlqReplayCommand;
use Freyr\MessageBroker\Console\DlqShowCommand;
use Freyr\MessageBroker\Console\SetupSchemaCommand;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\DeadLetter\ReplayService;
use Freyr\MessageBroker\Outbox\PdoOutboxStore;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Storage\Platform;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Transport\PdoDeduplicationStore;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ConsoleCommandsTest extends FunctionalTestCase
{
    private Platform $platform;
    private PdoDeadLetterStore $deadLetters;

    protected function setUp(): void
    {
        parent::setUp();
        $this->platform = static::platform();
        $this->deadLetters = new PdoDeadLetterStore(self::$pdo, $this->platform);
    }

    public function testSetupSchemaDumpSqlPrintsDdlWithoutTouchingTheDatabase(): void
    {
        $tester = new CommandTester(new SetupSchemaCommand(self::$pdo, $this->platform));

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
        $tester = new CommandTester(new SetupSchemaCommand(self::$pdo, $this->platform));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $schemaPredicate = static::isPostgres() ? 'table_schema = current_schema()' : 'table_schema = DATABASE()';
        self::assertSame(1, self::fetchInt(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE {$schemaPredicate} AND table_name = 'outbox_messages'",
        ));
    }

    public function testDedupCleanupParsesDurationAndPrunes(): void
    {
        $store = new PdoDeduplicationStore(self::$pdo, $this->platform);
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
            messageId: 'm-1',
            messageName: 'order.placed',
            body: (string) json_encode([
                'metadata' => [
                    'message_id' => 'm-1',
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
            new ReplayService($this->deadLetters, new PdoOutboxStore(
                self::$pdo,
                $this->platform
            ), new JsonWireFormat()),
            $this->deadLetters,
        ));
        $replay->execute([
            'id' => $deadLetter->id,
            '--lane' => 'orders',
        ]);
        $replay->assertCommandIsSuccessful();
        self::assertSame(
            1,
            self::fetchInt("SELECT COUNT(*) FROM outbox_messages WHERE lane = 'orders' AND id = 'm-1'"),
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

    public function testSetupSchemaAvroFormatUsesLongblobBody(): void
    {
        $tester = new CommandTester(new SetupSchemaCommand(self::$pdo, $this->platform));
        $tester->execute([
            '--format' => 'avro',
            '--dump-sql' => true,
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString(
            static::isPostgres() ? 'body BYTEA' : 'body LONGBLOB',
            $tester->getDisplay(),
        );
    }

    public function testReplayAllFailsClosedWithoutForceOnNonTty(): void
    {
        $this->seedDeadLetter('m-a', 'order.placed');

        $replay = new CommandTester(new DlqReplayCommand(
            new ReplayService($this->deadLetters, new PdoOutboxStore(
                self::$pdo,
                $this->platform
            ), new JsonWireFormat()),
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
        $this->seedDeadLetter('m-b', 'order.placed');

        $replay = new CommandTester(new DlqReplayCommand(
            new ReplayService($this->deadLetters, new PdoOutboxStore(
                self::$pdo,
                $this->platform
            ), new JsonWireFormat()),
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
        $this->seedDeadLetter('m-c', 'order.placed');

        $replay = new CommandTester(new DlqReplayCommand(
            new ReplayService($this->deadLetters, new PdoOutboxStore(
                self::$pdo,
                $this->platform
            ), new JsonWireFormat()),
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
        self::assertSame(1, self::fetchInt("SELECT COUNT(*) FROM outbox_messages WHERE lane = 'orders'"));
    }

    public function testPurgeFailsClosedWithoutForceButDryRunPreviews(): void
    {
        $this->seedDeadLetter('m-p1', 'order.placed');
        $this->seedDeadLetter('m-p2', 'order.cancelled');

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
        $store = new PdoDeduplicationStore(self::$pdo, $this->platform);
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
        $this->seedDeadLetter('m-1', 'order.placed');
        $this->seedDeadLetter('m-2', 'order.placed');

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
        $this->seedDeadLetter('m-1', 'order.placed');
        $this->seedDeadLetter('m-2', 'order.placed');
        $alreadyReplayed = $this->seedDeadLetter('m-3', 'order.placed');
        $this->deadLetters->markReplayed($alreadyReplayed->id);

        // batchSize 1 forces the drain through multiple pages.
        $replay = new CommandTester(new DlqReplayCommand(
            new ReplayService($this->deadLetters, new PdoOutboxStore(
                self::$pdo,
                $this->platform
            ), new JsonWireFormat()),
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
        self::assertStringContainsString('Replayed 2', $replay->getDisplay());
        self::assertSame(2, self::fetchInt("SELECT COUNT(*) FROM outbox_messages WHERE lane = 'orders'"));
        self::assertSame(
            0,
            self::fetchInt("SELECT COUNT(*) FROM outbox_messages WHERE id = 'm-3'"),
            'an already-replayed dead letter must not be replayed again',
        );
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM dead_letters WHERE replayed_at IS NULL'));
    }

    private function seedDeadLetter(string $id, string $name): DeadLetter
    {
        $deadLetter = DeadLetter::fromFailure(
            source: 'orders_q',
            messageId: $id,
            messageName: $name,
            body: (string) json_encode([
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
            error: new RuntimeException('boom'),
            attempts: 5,
        );
        $this->deadLetters->store($deadLetter);

        return $deadLetter;
    }
}
