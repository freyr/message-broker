<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Kafka;

use Freyr\MessageBroker\Consumer\CallableDispatcher;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Outbox\PdoOutboxStore;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Serializer\Avro\AvroDeserializer;
use Freyr\MessageBroker\Serializer\Avro\AvroWireFormat;
use Freyr\MessageBroker\Serializer\Avro\FileSchemaStore;
use Freyr\MessageBroker\Serializer\Avro\HttpSchemaRegistry;
use Freyr\MessageBroker\Serializer\Format;
use Freyr\MessageBroker\Tests\Fixtures\OrderPlaced;
use Freyr\MessageBroker\Tests\Functional\Avro\RegistersSchemas;
use Freyr\MessageBroker\Transport\Kafka\KafkaConsumer;
use Freyr\MessageBroker\Transport\Kafka\KafkaConsumerConfig;
use Freyr\MessageBroker\Transport\Kafka\KafkaPublishConfig;
use Freyr\MessageBroker\Transport\Kafka\KafkaRelay;
use Freyr\MessageBroker\Transport\Kafka\KafkaRetryPolicy;
use Freyr\MessageBroker\Transport\PdoDeduplicationStore;

final class KafkaAvroEndToEndTest extends KafkaTestCase
{
    use RegistersSchemas;

    private const string SCHEMA_PATH = __DIR__.'/../../Fixtures/schemas/order_placed.avsc';

    /** @var list<IncomingMessage> */
    private array $dispatched = [];

    private OutboxStore $store;
    private OutboxProducer $producer;
    private string $topic;
    private string $group;

    protected static function outboxFormat(): Format
    {
        return Format::Avro; // outbox body column is Avro (LONGBLOB)
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $schemaJson = file_get_contents(self::SCHEMA_PATH);
        self::assertNotFalse($schemaJson);
        self::registerSchema('order.placed', $schemaJson);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatched = [];
        $this->store = new PdoOutboxStore(self::$pdo, static::platform());
        $schemas = new FileSchemaStore([
            'order.placed' => self::SCHEMA_PATH,
        ]);
        $this->producer = new OutboxProducer(
            $this->store,
            new AvroWireFormat($schemas, new HttpSchemaRegistry(self::registryUrl())),
            lane: 'orders',
        );
        $this->topic = $this->uniqueTopic('mb_avro');
        $this->group = $this->uniqueGroup('mb_avro');
    }

    public function testAvroRoundTripFromProduceToDispatcher(): void
    {
        $message = OrderPlaced::create('o-42', 12_500);
        self::$pdo->beginTransaction();
        $this->producer->produce($message, headers: [
            'correlation_id' => 'corr-7',
        ]);
        self::$pdo->commit();

        $relay = new KafkaRelay(
            outbox: $this->store,
            publish: new KafkaPublishConfig(brokers: self::brokers(), topic: $this->topic),
            lane: 'orders',
        );
        self::assertSame(1, $relay->drainOnce());

        $dispatch = function (IncomingMessage $incoming): void {
            $this->dispatched[] = $incoming;
        };

        $consumer = new KafkaConsumer(
            config: new KafkaConsumerConfig(brokers: self::brokers(), topic: $this->topic, groupId: $this->group),
            deserializer: new AvroDeserializer(new HttpSchemaRegistry(self::registryUrl())),
            dispatcher: new CallableDispatcher($dispatch),
            pdo: self::$pdo,
            deduplication: new PdoDeduplicationStore(self::$pdo, static::platform()),
            retryPolicy: new KafkaRetryPolicy(maxAttempts: 2, backoff: Backoff::exponential(100, 100)),
            deadLetters: new PdoDeadLetterStore(self::$pdo, static::platform()),
            name: 'avro_consumer',
        );
        $consumer->run(messageLimit: 1, idleTimeoutSec: 10);

        self::assertCount(1, $this->dispatched);
        self::assertSame('o-42', $this->dispatched[0]->payload['order_id']);
        self::assertSame(12_500, $this->dispatched[0]->payload['total_cents']);
        self::assertSame($message->id, $this->dispatched[0]->messageId);
        self::assertSame(
            $message->createdAt,
            $this->dispatched[0]->createdAt,
            'x-created-at survives as int through Kafka byte headers'
        );
        self::assertSame('corr-7', $this->dispatched[0]->headers['correlation_id']);
    }
}
