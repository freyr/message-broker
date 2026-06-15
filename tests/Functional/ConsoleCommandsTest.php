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
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Storage\MySqlPlatform;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Transport\PdoDeduplicationStore;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

final class ConsoleCommandsTest extends FunctionalTestCase
{
    private MySqlPlatform $platform;
    private PdoDeadLetterStore $deadLetters;

    protected function setUp(): void
    {
        parent::setUp();
        $this->platform = new MySqlPlatform();
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
        self::assertSame(1, self::fetchInt("SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'outbox_messages'"));
    }

    public function testDedupCleanupParsesDurationAndPrunes(): void
    {
        $store = new PdoDeduplicationStore(self::$pdo, $this->platform);
        $store->acquire(new IncomingMessage('m-old', 'order.placed', EpochMillis::now(), []), 'c');
        self::$pdo->exec('UPDATE message_deduplication SET created_at = DATE_SUB(NOW(3), INTERVAL 8 DAY)');
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
            new ReplayService($this->deadLetters, new OutboxStore(self::$pdo, $this->platform), new JsonWireFormat()),
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
        $purge->execute([]);
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
        self::assertStringContainsString('body LONGBLOB', $tester->getDisplay());
    }
}
