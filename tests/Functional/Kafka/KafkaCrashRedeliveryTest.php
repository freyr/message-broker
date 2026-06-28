<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Kafka;

use Freyr\MessageBroker\Consumer\CallableDispatcher;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Serializer\JsonDeserializer;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Tests\Fixtures\OrderPlaced;
use Freyr\MessageBroker\Transport\Kafka\KafkaConsumer;
use Freyr\MessageBroker\Transport\Kafka\KafkaConsumerConfig;
use Freyr\MessageBroker\Transport\Kafka\KafkaPublishConfig;
use Freyr\MessageBroker\Transport\Kafka\KafkaRelay;
use Freyr\MessageBroker\Transport\Kafka\KafkaRetryPolicy;
use Freyr\MessageBroker\Transport\PdoDeduplicationStore;
use RdKafka\KafkaConsumer as RdKafkaConsumer;
use RdKafka\Message as KafkaMessage;
use RuntimeException;

final class KafkaCrashRedeliveryTest extends KafkaTestCase
{
    /** @var list<IncomingMessage> */
    private array $dispatched = [];

    public function testCrashBeforeOffsetCommitRedeliversAndDedupAbsorbs(): void
    {
        $store = new OutboxStore(self::$pdo, static::platform());
        $producer = new OutboxProducer($store, new JsonWireFormat(), lane: 'orders');
        $topic = $this->uniqueTopic('mb_crash');
        $group = $this->uniqueGroup('mb_crash'); // SAME group for both consumers

        $message = OrderPlaced::create('o-1', 100);
        $producer->produce($message);

        $relay = new KafkaRelay(
            outbox: $store,
            publish: new KafkaPublishConfig(brokers: self::brokers(), topic: $topic),
            lane: 'orders',
        );
        self::assertSame(1, $relay->drainOnce());

        $dispatch = function (IncomingMessage $incoming): void {
            $this->dispatched[] = $incoming;
        };

        // Consumer A: the DB tx commits, but the offset commit "crashes".
        $crashing = new KafkaConsumer(
            config: new KafkaConsumerConfig(brokers: self::brokers(), topic: $topic, groupId: $group),
            deserializer: new JsonDeserializer(),
            dispatcher: new CallableDispatcher($dispatch),
            pdo: self::$pdo,
            deduplication: new PdoDeduplicationStore(self::$pdo, static::platform()),
            retryPolicy: new KafkaRetryPolicy(maxAttempts: 2, backoff: Backoff::exponential(100, 100)),
            deadLetters: new PdoDeadLetterStore(self::$pdo, static::platform()),
            name: 'crash_consumer',
            offsetCommitter: static function (RdKafkaConsumer $consumer, KafkaMessage $message): void {
                throw new RuntimeException('simulated crash before offset commit');
            },
        );

        try {
            $crashing->run(messageLimit: 1, idleTimeoutSec: 10);
            self::fail('the simulated crash was expected to propagate');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('simulated crash', $error->getMessage());
        }

        // The work committed exactly once, but the offset never did.
        self::assertCount(1, $this->dispatched, 'consumer A processed the message once');
        self::assertSame(
            1,
            self::fetchInt("SELECT COUNT(*) FROM message_deduplication WHERE message_id = '{$message->id}'")
        );

        // Consumer B: same group, default (real) committer — resumes from the
        // last committed offset (none), so the message is REDELIVERED.
        $resuming = new KafkaConsumer(
            config: new KafkaConsumerConfig(brokers: self::brokers(), topic: $topic, groupId: $group),
            deserializer: new JsonDeserializer(),
            dispatcher: new CallableDispatcher($dispatch),
            pdo: self::$pdo,
            deduplication: new PdoDeduplicationStore(self::$pdo, static::platform()),
            retryPolicy: new KafkaRetryPolicy(maxAttempts: 2, backoff: Backoff::exponential(100, 100)),
            deadLetters: new PdoDeadLetterStore(self::$pdo, static::platform()),
            name: 'crash_consumer',
        );
        $resuming->run(messageLimit: 1, idleTimeoutSec: 15);

        // Redelivered, but dedup absorbed it — still dispatched exactly once.
        self::assertCount(1, $this->dispatched, 'redelivery must be absorbed by dedup (exactly-once processing)');
        self::assertSame(
            1,
            self::fetchInt("SELECT COUNT(*) FROM message_deduplication WHERE message_id = '{$message->id}'")
        );
        self::assertSame(
            0,
            self::fetchInt('SELECT COUNT(*) FROM dead_letters'),
            'no dead letters on a clean redelivery'
        );
    }
}
