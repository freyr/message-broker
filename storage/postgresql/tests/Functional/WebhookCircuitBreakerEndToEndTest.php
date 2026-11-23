<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlCircuitBreaker;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeliveryStore;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlRateLimiter;
use Freyr\MessageBroker\Testing\FakeDeliverySender;
use Freyr\MessageBroker\Testing\InMemorySubscriptionProvider;
use Freyr\MessageBroker\Testing\OrderPlaced;
use Freyr\MessageBroker\Webhook\CircuitBreakerPolicy;
use Freyr\MessageBroker\Webhook\DefaultDeliveryRetryPolicy;
use Freyr\MessageBroker\Webhook\DeliveryLoop;
use Freyr\MessageBroker\Webhook\DeliveryLoopConfig;
use Freyr\MessageBroker\Webhook\DeliveryPair;
use Freyr\MessageBroker\Webhook\DeliveryResult;
use Freyr\MessageBroker\Webhook\Rate;
use Freyr\MessageBroker\Webhook\Subscription;
use Freyr\MessageBroker\Webhook\WebhookProducer;
use PDO;

/**
 * The success criterion "circuit breaker" of the webhook flow control, end to
 * end on PostgreSQL: the delivery loop with the PostgreSQL delivery store,
 * rate limiter and circuit breaker, and FakeDeliverySender. A lane whose
 * endpoint always fails leaves circulation for the open time: its rows get no
 * request and use no attempt, and after the open time the lane gets requests
 * again. The other lane is not affected.
 */
final class WebhookCircuitBreakerEndToEndTest extends FunctionalTestCase
{
    private const int OPEN_MS = 1_000;

    /** The passes of the open time stop this long before open_until: openLanes() in a pass reads a later database time. */
    private const int MARGIN_MS = 200;

    public function testALaneWhoseEndpointAlwaysFailsGetsNoRequestDuringTheOpenTimeAndRequestsAfterIt(): void
    {
        $subscriptions = new InMemorySubscriptionProvider();
        $subscriptions->add(new Subscription('crm', 'https://crm.example/hook', ['whsec_x']), 'order.placed');
        $subscriptions->add(new Subscription('erp', 'https://erp.example/hook', ['whsec_x']), 'order.placed');
        $deliveries = new PostgreSqlDeliveryStore(self::$pdo);
        $producer = new WebhookProducer($subscriptions, $deliveries, new JsonWireFormat());
        for ($order = 1; $order <= 10; ++$order) {
            $producer->produce(OrderPlaced::create("o-{$order}", 100));
        }
        $sender = new FakeDeliverySender(maxSendMs: 1);
        $sender->respond = static fn (DeliveryPair $pair): DeliveryResult => $pair->subscription->id === 'crm'
            ? DeliveryResult::failed(5, 'HTTP 500')
            : DeliveryResult::delivered(5);
        $breaker = new PostgreSqlCircuitBreaker(self::$pdo, new CircuitBreakerPolicy(minimumRequests: 5, openMs: self::OPEN_MS));
        $loop = new DeliveryLoop(
            $deliveries,
            $sender,
            $subscriptions,
            retryPolicy: new DefaultDeliveryRetryPolicy(array_fill(0, 20, 1)),
            config: new DeliveryLoopConfig(leaseMs: 1_000, batchSize: 100, idleSleepMs: 1, globalRate: new Rate(10_000, 100)),
            rateLimiter: new PostgreSqlRateLimiter(self::$pdo),
            circuitBreaker: $breaker,
        );

        self::assertSame(10, $loop->deliverOnce(), 'the 10 rows of erp are delivered; the 10 rows of crm fail');
        self::assertSame(['crm'], $breaker->openLanes(), 'crm left circulation');
        $attempts = self::crmAttempts();
        self::assertSame(array_fill(0, 10, 1), $attempts);

        $openUntil = $breaker->all()[0]->openUntil;
        self::assertNotNull($openUntil);
        $sender->calls = [];
        $passes = 0;
        while (self::databaseTime() < $openUntil - self::MARGIN_MS) {
            $loop->deliverOnce();
            ++$passes;
            usleep(5_000);
        }
        self::assertGreaterThan(10, $passes, 'many passes in the open time');
        self::assertSame([], $sender->calls, 'no request for crm in the open time, and erp has no row left');
        self::assertSame($attempts, self::crmAttempts(), 'the waiting rows of crm used no attempt');
        self::assertSame(10, self::fetchInt("SELECT COUNT(*) FROM webhook_deliveries WHERE lane = 'crm' AND completed_at IS NULL AND available_at <= CAST(now() AT TIME ZONE 'UTC' AS TIMESTAMP(3))"), 'and were due all the time');

        // No pass in the margin: wait until the open time ended.
        $deadline = hrtime(true) + 2_000_000_000;
        while ($breaker->openLanes() !== [] && hrtime(true) < $deadline) {
            usleep(5_000);
        }
        self::assertSame([], $breaker->openLanes(), 'the open time ended');

        $loop->deliverOnce();

        self::assertCount(1, $sender->calls, 'after the open time crm gets requests again');
        self::assertSame(
            array_fill(0, 10, 'crm'),
            array_map(static fn (DeliveryPair $pair): string => $pair->subscription->id, $sender->calls[0]),
        );
        self::assertSame(array_fill(0, 10, 2), self::crmAttempts());
    }

    /** The database time of the breaker, in epoch milliseconds. */
    private static function databaseTime(): int
    {
        return self::fetchInt("SELECT CAST(EXTRACT(EPOCH FROM CAST(now() AT TIME ZONE 'UTC' AS TIMESTAMP(3))) * 1000 AS BIGINT)");
    }

    /** @return list<int> the attempts of the rows of crm */
    private static function crmAttempts(): array
    {
        $statement = self::$pdo->query("SELECT attempts FROM webhook_deliveries WHERE lane = 'crm' ORDER BY id");
        self::assertNotFalse($statement);

        /** @var list<int> */
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }
}
