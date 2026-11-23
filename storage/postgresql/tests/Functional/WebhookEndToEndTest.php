<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Freyr\MessageBroker\Console\DlqListCommand;
use Freyr\MessageBroker\Console\DlqReplayCommand;
use Freyr\MessageBroker\Console\DlqShowCommand;
use Freyr\MessageBroker\Console\WebhookAbandonCommand;
use Freyr\MessageBroker\Console\WebhookRunCommand;
use Freyr\MessageBroker\DeadLetter\AlreadyReplayed;
use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\DeadLetter\ReplayService;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Storage\PostgreSql\PdoTransaction;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeadLetterStore;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeliveryRetention;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeliveryStore;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxStore;
use Freyr\MessageBroker\Testing\FakeDeliverySender;
use Freyr\MessageBroker\Testing\InMemorySubscriptionProvider;
use Freyr\MessageBroker\Testing\OrderPlaced;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Webhook\DefaultDeliveryRetryPolicy;
use Freyr\MessageBroker\Webhook\DeliveryLoop;
use Freyr\MessageBroker\Webhook\DeliveryLoopConfig;
use Freyr\MessageBroker\Webhook\DeliveryPair;
use Freyr\MessageBroker\Webhook\DeliveryResult;
use Freyr\MessageBroker\Webhook\Subscription;
use Freyr\MessageBroker\Webhook\WebhookProducer;
use Freyr\MessageBroker\Worker\WorkerLimits;
use PDO;
use PDOException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The success criteria of the webhook delivery queue, end to end on
 * PostgreSQL: the producer, the delivery loop with FakeDeliverySender, the
 * dead letters, the dlq commands and the replay to the delivery table.
 *
 * "After the lease" waits on the database clock (the claim and the due check
 * use it) with a lease of a few milliseconds, never on a long sleep.
 */
final class WebhookEndToEndTest extends FunctionalTestCase
{
    private const int LEASE_MS = 5;

    private PostgreSqlDeliveryStore $deliveries;
    private PostgreSqlDeadLetterStore $deadLetters;
    private InMemorySubscriptionProvider $subscriptions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deliveries = new PostgreSqlDeliveryStore(self::$pdo);
        $this->deadLetters = new PostgreSqlDeadLetterStore(self::$pdo);
        $this->subscriptions = new InMemorySubscriptionProvider();
        $this->subscriptions->add(new Subscription('crm', 'https://crm.example/hook', ['whsec_x']), 'order.placed');
    }

    public function testAWorkerThatStopsBeforeTheResultWriteLosesNoRowAndTheNextRunSendsTheSameMessageIdAgain(): void
    {
        $this->produce();
        $crashed = new FakeDeliverySender(maxSendMs: 1);
        $crashed->respond = static function (DeliveryPair $pair): DeliveryResult {
            throw new RuntimeException('the worker stops after the request and before complete()');
        };

        try {
            $this->loop($crashed)->run();
            self::fail('the first worker must stop');
        } catch (RuntimeException $stop) {
            self::assertSame('the worker stops after the request and before complete()', $stop->getMessage());
        }
        self::assertCount(1, $crashed->calls, 'the first worker sent the delivery');
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM webhook_deliveries WHERE completed_at IS NOT NULL'));

        self::waitUntilTheLeaseEnds();
        $second = new FakeDeliverySender(maxSendMs: 1);
        $this->loop($second)->run(new WorkerLimits(maxMessages: 1));

        self::assertSame($crashed->sentIds(), $second->sentIds(), 'the second run sends the same delivery row');
        self::assertSame(
            $crashed->calls[0][0]->claimed->delivery->messageId,
            $second->calls[0][0]->claimed->delivery->messageId,
            'with the same message ID',
        );
        $row = self::onlyRow();
        self::assertSame(1, $row['outcome'], 'the row ends as delivered');
        self::assertSame(1, $row['attempts'], 'the stopped request used no attempt');
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM dead_letters'));
    }

    public function testAFailedEndpointGivesADeadLetterThatTheDlqCommandsPrintAndReplayToTheLaneOfTheSource(): void
    {
        $this->produce();
        $failing = new FakeDeliverySender(maxSendMs: 1);
        $failing->respond = static fn (DeliveryPair $pair): DeliveryResult => DeliveryResult::failed(3, 'HTTP 500: upstream down');
        $loop = $this->loop($failing, new DefaultDeliveryRetryPolicy([1, 1]));

        foreach ([1, 2, 3] as $attempt) {
            self::waitUntilARowIsDue();
            self::assertSame(0, $loop->deliverOnce());
            self::assertSame($attempt, self::onlyRow()['attempts']);
        }

        self::assertSame(2, self::onlyRow()['outcome'], 'the row is dead after the 3rd attempt');
        $deadLetter = $this->onlyDeadLetter();
        self::assertSame('webhook:crm', $deadLetter->source);
        self::assertSame('failed', $deadLetter->errorClass);
        self::assertSame('HTTP 500: upstream down', $deadLetter->errorMessage);
        self::assertSame(3, $deadLetter->attempts);
        self::assertSame('{"order_id":"o-1","total_cents":4999}', $deadLetter->body);

        $list = new CommandTester(new DlqListCommand($this->deadLetters));
        self::assertSame(Command::SUCCESS, $list->execute([]));
        self::assertMatchesRegularExpression(
            '/\| '.preg_quote($deadLetter->id, '/').' \| order\.placed\s+\| webhook:crm \| 3\s+\|.*\| no\s+\| yes\s+\| HTTP 500: upstream down \|/',
            $list->getDisplay(),
        );

        $show = new CommandTester(new DlqShowCommand($this->deadLetters));
        self::assertSame(Command::SUCCESS, $show->execute([
            'id' => $deadLetter->id,
        ]));
        self::assertStringContainsString("source:        webhook:crm\n", $show->getDisplay());
        self::assertStringContainsString("error_class:   failed\n", $show->getDisplay());
        self::assertStringContainsString("body:\n{\"order_id\":\"o-1\",\"total_cents\":4999}\n", $show->getDisplay());

        $replay = new CommandTester(new DlqReplayCommand($this->replayService(), $this->deadLetters));
        self::assertSame(Command::SUCCESS, $replay->execute([
            'id' => $deadLetter->id,
            '--lane' => 'orders',
        ]));

        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'), 'no outbox row');
        $new = self::rows('SELECT * FROM webhook_deliveries WHERE completed_at IS NULL');
        self::assertCount(1, $new, 'one new delivery row');
        self::assertSame('crm', $new[0]['lane']);
        self::assertSame($deadLetter->messageId, $new[0]['message_id']);
        self::assertSame(0, $new[0]['attempts']);
        self::assertSame($deadLetter->body, self::bodyOf($new[0]['body']));
        self::assertNotNull($this->deadLetters->find($deadLetter->id)?->replayedAt);

        // The replayed row is delivered by the next run.
        $healthy = new FakeDeliverySender(maxSendMs: 1);
        (new CommandTester(new WebhookRunCommand(
            fn (DeliveryLoopConfig $config): DeliveryLoop => new DeliveryLoop($this->deliveries, $healthy, $this->subscriptions, config: $config),
            self::config(),
        )))->execute([
            '--limit' => '1',
        ]);
        self::assertSame([$new[0]['id']], $healthy->sentIds());
        self::assertSame(1, self::fetchInt("SELECT outcome FROM webhook_deliveries WHERE id = '{$new[0]['id']}'"));
    }

    public function testARowOfThirtyDaysAgoStaysDueAndIsDelivered(): void
    {
        $old = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('-30 day');
        (new PostgreSqlDeliveryRetention(self::$pdo))->ensureCapacity(0, $old);
        $createdAt = $old->format('Y-m-d 10:00:00');
        self::$pdo->exec(
            "INSERT INTO webhook_deliveries (id, message_id, lane, message_name, body, headers, created_at, available_at)
             VALUES (gen_random_uuid(), gen_random_uuid(), 'crm', 'order.placed', '\\x7b7d', '{}', '{$createdAt}', '{$createdAt}')",
        );
        $sender = new FakeDeliverySender(maxSendMs: 1);

        $this->loop($sender)->run(new WorkerLimits(maxMessages: 1));

        self::assertCount(1, $sender->sentIds());
        self::assertSame(1, self::onlyRow()['outcome'], 'delivered: a delivery has no time limit');
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM dead_letters'));
    }

    public function testAnAbandonedRowGivesADeadLetterThatDlqReplaySendsAgainToItsLane(): void
    {
        $this->produce();
        $abandon = new CommandTester(new WebhookAbandonCommand($this->deliveries));
        self::assertSame(Command::SUCCESS, $abandon->execute([
            '--before' => (new DateTimeImmutable('tomorrow', new DateTimeZone('UTC')))->format('Y-m-d'),
        ]));

        self::assertSame(2, self::onlyRow()['outcome'], 'the row is dead');
        $deadLetter = $this->onlyDeadLetter();
        self::assertSame('webhook:crm', $deadLetter->source);
        self::assertSame('abandoned', $deadLetter->errorClass);
        self::assertSame(0, $deadLetter->attempts);

        $replay = new CommandTester(new DlqReplayCommand($this->replayService(), $this->deadLetters));
        self::assertSame(Command::SUCCESS, $replay->execute([
            'id' => $deadLetter->id,
        ]));

        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'), 'no outbox row');
        $new = self::rows('SELECT * FROM webhook_deliveries WHERE completed_at IS NULL');
        self::assertCount(1, $new, 'one new delivery row');
        self::assertSame('crm', $new[0]['lane']);
        self::assertSame($deadLetter->messageId, $new[0]['message_id']);
        self::assertSame($deadLetter->body, self::bodyOf($new[0]['body']));
    }

    public function testARowThatTheOperatorAbandonsWhileAWorkerHoldsItsLeaseStaysFinished(): void
    {
        $this->produce();
        $sender = new FakeDeliverySender(maxSendMs: 1);
        $sender->onSend = function (): void {
            self::assertSame(1, $this->deliveries->abandon(EpochMillis::now() + 60_000, null, 10));
        };
        $loop = new DeliveryLoop($this->deliveries, $sender, $this->subscriptions, config: new DeliveryLoopConfig(leaseMs: 60_000, idleSleepMs: 1));

        self::assertSame(0, $loop->deliverOnce(), 'the result write changes no row');

        self::assertCount(1, $sender->sentIds(), 'the worker sent its request');
        $row = self::onlyRow();
        self::assertSame(2, $row['outcome'], 'the row stays dead');
        self::assertSame(0, $row['attempts']);
        self::assertSame('abandoned', $this->onlyDeadLetter()->errorClass);
    }

    public function testASecondReplayOfAWebhookDeadLetterIsAlreadyReplayedAndInsertsNoRow(): void
    {
        $deadLetter = $this->storeWebhookDeadLetter();
        $service = $this->replayService();
        $service->replay($deadLetter->id);

        try {
            $service->replay($deadLetter->id);
            self::fail('Expected AlreadyReplayed');
        } catch (AlreadyReplayed) {
        }

        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM webhook_deliveries'));
    }

    public function testAFailedDeliveryInsertRollsTheReplayMarkBack(): void
    {
        $deadLetter = $this->storeWebhookDeadLetter();
        // No partition for today: the insert fails in the database. setUp() of the next test creates it again.
        self::$pdo->exec('DROP TABLE webhook_deliveries_p'.gmdate('Ymd'));

        try {
            $this->replayService()->replay($deadLetter->id);
            self::fail('Expected the insert error');
        } catch (PDOException) {
        }

        self::assertFalse(self::$pdo->inTransaction());
        self::assertNull($this->deadLetters->find($deadLetter->id)?->replayedAt, 'the mark rolled back');
    }

    public function testDryRunOfAWebhookDeadLetterWithABodyThatIsNotACanonicalDocumentPasses(): void
    {
        $deadLetter = $this->storeWebhookDeadLetter("\x01raw bytes, not a document");
        $tester = new CommandTester(new DlqReplayCommand($this->replayService(), $this->deadLetters));

        $status = $tester->execute([
            'id' => $deadLetter->id,
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM webhook_deliveries'));
        self::assertNull($this->deadLetters->find($deadLetter->id)?->replayedAt);
    }

    public function testADeadLetterWithAConsumerSourceStillGoesToTheOutboxWithTheReplayTarget(): void
    {
        $message = OrderPlaced::create('o-1', 4999);
        $deadLetter = DeadLetter::fromFailure(
            source: 'orders_q',
            messageId: $message->id,
            messageName: $message->name,
            body: (string) json_encode([
                'metadata' => [
                    'message_id' => $message->id,
                    'message_name' => $message->name,
                ],
                'payload' => $message->payload(),
            ]),
            headers: [],
            error: new RuntimeException('handler exploded'),
            attempts: 5,
        );
        $this->deadLetters->store($deadLetter);

        $this->replayService()->replay($deadLetter->id, 'orders');

        $rows = self::rows('SELECT lane, headers FROM outbox_messages');
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('orders', $row['lane']);
        self::assertSame([
            'x-replay-target' => 'orders_q',
        ], json_decode((string) $row['headers'], true));
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM webhook_deliveries'));
    }

    private function produce(): void
    {
        (new WebhookProducer($this->subscriptions, $this->deliveries, new JsonWireFormat()))->produce(OrderPlaced::create('o-1', 4999));
    }

    private function loop(FakeDeliverySender $sender, ?DefaultDeliveryRetryPolicy $policy = null): DeliveryLoop
    {
        return new DeliveryLoop(
            $this->deliveries,
            $sender,
            $this->subscriptions,
            retryPolicy: $policy ?? new DefaultDeliveryRetryPolicy(),
            config: self::config(),
        );
    }

    private static function config(): DeliveryLoopConfig
    {
        return new DeliveryLoopConfig(leaseMs: self::LEASE_MS, idleSleepMs: 1);
    }

    private function replayService(): ReplayService
    {
        return new ReplayService(
            $this->deadLetters,
            new PostgreSqlOutboxStore(self::$pdo),
            new PdoTransaction(self::$pdo),
            new JsonWireFormat(),
            deliveries: $this->deliveries,
        );
    }

    private function storeWebhookDeadLetter(string $body = '{"order_id":"o-1"}'): DeadLetter
    {
        $deadLetter = new DeadLetter(
            id: '01900000-0000-7000-8000-0000000000d1',
            source: 'webhook:crm',
            messageId: '01900000-0000-7000-9000-0000000000d1',
            messageName: 'order.placed',
            body: $body,
            headers: [],
            errorClass: 'failed',
            errorMessage: 'HTTP 500',
            errorTrace: '',
            attempts: 8,
            failedAt: 1_750_000_000_000,
        );
        $this->deadLetters->store($deadLetter);

        return $deadLetter;
    }

    private function onlyDeadLetter(): DeadLetter
    {
        $all = $this->deadLetters->list(new DeadLetterFilter());
        self::assertCount(1, $all);

        return $all[0];
    }

    /** @return array<string, mixed> */
    private static function onlyRow(): array
    {
        $rows = self::rows('SELECT * FROM webhook_deliveries');
        self::assertCount(1, $rows, 'no row is lost and no row is added');

        return $rows[0];
    }

    /** @return list<array<string, mixed>> */
    private static function rows(string $sql): array
    {
        $statement = self::$pdo->query($sql);
        self::assertNotFalse($statement);

        /** @var list<array<string, mixed>> */
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Wait on the database clock until the leased row is due again; the lease is a few milliseconds. */
    private static function waitUntilTheLeaseEnds(): void
    {
        self::waitUntilARowIsDue();
    }

    private static function waitUntilARowIsDue(): void
    {
        $due = "SELECT COUNT(*) FROM webhook_deliveries
                WHERE completed_at IS NULL AND available_at <= CAST(now() AT TIME ZONE 'UTC' AS TIMESTAMP(3))";
        for ($i = 0; $i < 2_000 && self::fetchInt($due) === 0; ++$i) {
            usleep(1_000);
        }
        self::assertSame(1, self::fetchInt($due), 'a row is due');
    }
}
