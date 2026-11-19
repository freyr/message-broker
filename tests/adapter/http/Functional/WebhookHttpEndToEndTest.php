<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Http\Tests\Functional;

use Freyr\MessageBroker\Adapter\Http\HttpDeliverySender;
use Freyr\MessageBroker\Adapter\Http\HttpSenderConfig;
use Freyr\MessageBroker\Adapter\PostgreSql\PdoTransaction;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeadLetterStore;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeliveryStore;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlOutboxStore;
use Freyr\MessageBroker\Adapter\PostgreSql\Testing\PostgreSqlTestSupport;
use Freyr\MessageBroker\Console\DlqListCommand;
use Freyr\MessageBroker\Console\DlqReplayCommand;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\DeadLetter\ReplayService;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Testing\InMemorySubscriptionProvider;
use Freyr\MessageBroker\Testing\OrderPlaced;
use Freyr\MessageBroker\Webhook\DefaultDeliveryRetryPolicy;
use Freyr\MessageBroker\Webhook\DeliveryLoop;
use Freyr\MessageBroker\Webhook\DeliveryLoopConfig;
use Freyr\MessageBroker\Webhook\Subscription;
use Freyr\MessageBroker\Webhook\WebhookProducer;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The success criterion "Failed endpoint", end to end: the delivery loop
 * with the PostgreSQL store and HttpDeliverySender sends to an endpoint of
 * the test HTTP server that always returns 500.
 */
final class WebhookHttpEndToEndTest extends TestCase
{
    use PostgreSqlTestSupport;

    private const string LANE = 'crm';

    public function testAnEndpointThatAlwaysFailsGivesADeadLetterThatTheDlqCommandsPrintAndReplayToItsLane(): void
    {
        $server = new TestServer();
        $subscriptions = new InMemorySubscriptionProvider();
        $subscriptions->add(
            new Subscription(self::LANE, $server->url('down', [
                'status' => 500,
                'body' => 'upstream down',
            ]), ['whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw']),
            'order.placed',
        );
        $deliveries = new PostgreSqlDeliveryStore(self::$pdo);
        $deadLetters = new PostgreSqlDeadLetterStore(self::$pdo);
        (new WebhookProducer($subscriptions, $deliveries, new JsonWireFormat()))->produce(OrderPlaced::create('o-1', 4999));

        $sender = new HttpDeliverySender(new HttpSenderConfig(
            totalTimeoutMs: 2_000,
            connectTimeoutMs: 1_000,
            dnsAllowanceMs: 0,
            blockPrivateNetworks: false,
            allowHttp: true,
        ));
        // Three attempts with a retry delay of 1 ms; the lease is longer than maxSendMs(), as run() requires.
        $loop = new DeliveryLoop(
            $deliveries,
            $sender,
            $subscriptions,
            retryPolicy: new DefaultDeliveryRetryPolicy([1, 1]),
            config: new DeliveryLoopConfig(leaseMs: $sender->maxSendMs() + 1_000, idleSleepMs: 1),
        );

        foreach ([1, 2, 3] as $attempt) {
            self::waitUntilARowIsDue();
            self::assertSame(0, $loop->deliverOnce());
            self::assertSame($attempt, self::fetchInt('SELECT attempts FROM webhook_deliveries'));
        }

        $requests = $server->requests();
        self::assertCount(3, $requests, 'one request for each attempt');
        self::assertCount(1, array_unique(array_map(static fn (array $request): string => $request['headers']['webhook-id'], $requests)), 'with the same webhook-id');
        self::assertSame(2, self::fetchInt('SELECT outcome FROM webhook_deliveries'), 'the row is dead after the 3rd attempt');

        $all = $deadLetters->list(new DeadLetterFilter());
        self::assertCount(1, $all);
        $deadLetter = $all[0];
        self::assertSame('webhook:'.self::LANE, $deadLetter->source);
        self::assertSame('failed', $deadLetter->errorClass);
        self::assertSame('HTTP 500: upstream down', $deadLetter->errorMessage);
        self::assertSame(3, $deadLetter->attempts);
        self::assertSame($requests[0]['headers']['webhook-id'], $deadLetter->messageId);

        $list = new CommandTester(new DlqListCommand($deadLetters));
        self::assertSame(Command::SUCCESS, $list->execute([]));
        self::assertMatchesRegularExpression(
            '/\| '.preg_quote($deadLetter->id, '/').' \| order\.placed\s+\| webhook:crm \| 3\s+\|.*\| HTTP 500: upstream down \|/',
            $list->getDisplay(),
        );

        $replayService = new ReplayService(
            $deadLetters,
            new PostgreSqlOutboxStore(self::$pdo),
            new PdoTransaction(self::$pdo),
            new JsonWireFormat(),
            deliveries: $deliveries,
        );
        $replay = new CommandTester(new DlqReplayCommand($replayService, $deadLetters));
        self::assertSame(Command::SUCCESS, $replay->execute([
            'id' => $deadLetter->id,
        ]));

        $statement = self::$pdo->query('SELECT lane, message_id, attempts FROM webhook_deliveries WHERE completed_at IS NULL');
        self::assertNotFalse($statement);
        self::assertSame([[
            'lane' => self::LANE,
            'message_id' => $deadLetter->messageId,
            'attempts' => 0,
        ]], $statement->fetchAll(PDO::FETCH_ASSOC), 'one new delivery row for the lane of the source');
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'), 'no outbox row');
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
