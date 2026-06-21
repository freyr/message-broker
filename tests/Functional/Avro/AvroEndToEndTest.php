<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Avro;

use Freyr\MessageBroker\Cache\ArrayCachePool;
use Freyr\MessageBroker\Consumer\CallableDispatcher;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\DeadLetter\ReplayService;
use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Serializer\Avro\AvroDeserializer;
use Freyr\MessageBroker\Serializer\Avro\AvroWireFormat;
use Freyr\MessageBroker\Serializer\Avro\FileSchemaStore;
use Freyr\MessageBroker\Serializer\Avro\HttpSchemaRegistry;
use Freyr\MessageBroker\Serializer\Avro\RegistryUnavailable;
use Freyr\MessageBroker\Serializer\Avro\SchemaNotFound;
use Freyr\MessageBroker\Serializer\Format;
use Freyr\MessageBroker\Storage\Platform;
use Freyr\MessageBroker\Tests\Fixtures\NeverRegistered;
use Freyr\MessageBroker\Tests\Fixtures\OrderPlaced;
use Freyr\MessageBroker\Tests\Functional\FunctionalTestCase;
use Freyr\MessageBroker\Transport\Amqp\AmqpConsumer;
use Freyr\MessageBroker\Transport\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpQueueConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpRelay;
use Freyr\MessageBroker\Transport\Amqp\AmqpRetryPolicy;
use Freyr\MessageBroker\Transport\PdoDeduplicationStore;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use RuntimeException;

/**
 * The Avro promise (slice 3, encode-at-produce): produce (Avro encoded at the
 * door → outbox `body` = Confluent-framed bytes, envelope in the `metadata`
 * column) → relay (byte-pump: verbatim body + individual x-message-* headers) →
 * RabbitMQ → consumer (registry-backed decode) → dispatcher — plus the
 * failure circle (DLQ stores a readable, replayable document) and both
 * operational failure modes (registry down; schema never registered, which now
 * fails at produce time, not the relay).
 */
final class AvroEndToEndTest extends FunctionalTestCase
{
    use RegistersSchemas;

    private const string EXCHANGE = 'mb_avro_e2e';
    private const string QUEUE = 'mb_avro_e2e_q';
    private const string LANE = 'avro_e2e';
    private const string SCHEMA_PATH = __DIR__.'/../../Fixtures/schemas/order_placed.avsc';

    protected static function outboxFormat(): Format
    {
        return Format::Avro;
    }

    private static AMQPStreamConnection $amqp;

    private AMQPChannel $channel;
    private AMQPChannel $relayChannel;
    private Platform $platform;
    private OutboxStore $outbox;
    private OutboxProducer $producer;
    private PdoDeadLetterStore $deadLetters;
    private AmqpRelay $relay;
    private FileSchemaStore $schemas;

    private bool $handlerFails = false;
    private int $handlerAttempts = 0;

    /** @var list<IncomingMessage> */
    private array $dispatched = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$amqp = new AMQPStreamConnection(
            host: getenv('AMQP_HOST') ?: 'rabbitmq',
            port: (int) (getenv('AMQP_PORT') ?: 5672),
            user: getenv('AMQP_USER') ?: 'guest',
            password: getenv('AMQP_PASSWORD') ?: 'guest',
            vhost: getenv('AMQP_VHOST') ?: '/',
        );

        // Out-of-band CI registration: idempotent, runs once per suite.
        $schemaJson = file_get_contents(self::SCHEMA_PATH);
        self::assertNotFalse($schemaJson);
        self::registerSchema('order.placed', $schemaJson);
        // 'order.never_registered' is intentionally NOT registered.
    }

    public static function tearDownAfterClass(): void
    {
        $channel = self::$amqp->channel();
        $channel->queue_delete(self::QUEUE);
        $channel->queue_delete(self::QUEUE.'.wait.100');
        $channel->exchange_delete(self::EXCHANGE);
        $channel->close();
        self::$amqp->close();

        // The unregistered-schema test registers 'order.never_registered' to prove
        // produce succeeds after registration.  Delete it here so other tests
        // (HttpSchemaRegistryTest) can still assert that the subject is absent.
        self::deleteSchema('order.never_registered');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatched = [];
        $this->handlerFails = false;
        $this->handlerAttempts = 0;

        $this->channel = self::$amqp->channel();
        $this->relayChannel = self::$amqp->channel();
        $this->channel->exchange_declare(self::EXCHANGE, 'topic', false, true, false);
        $this->channel->queue_declare(self::QUEUE, false, true, false, false);
        $this->channel->queue_bind(self::QUEUE, self::EXCHANGE, 'order.*');
        $this->channel->queue_purge(self::QUEUE);

        $this->platform = static::platform();
        $this->outbox = new OutboxStore(self::$pdo, $this->platform);
        $this->deadLetters = new PdoDeadLetterStore(self::$pdo, $this->platform);

        // FileSchemaStore for both 'order.placed' and 'order.never_registered'
        // (both point at the same .avsc — validator passes for both locally).
        $this->schemas = new FileSchemaStore([
            'order.placed' => self::SCHEMA_PATH,
            'order.never_registered' => self::SCHEMA_PATH,
        ]);

        $this->producer = new OutboxProducer(
            $this->outbox,
            new AvroWireFormat($this->schemas, new HttpSchemaRegistry(self::registryUrl())),
            lane: self::LANE,
        );

        $this->relay = new AmqpRelay(
            outbox: $this->outbox,
            amqp: $this->relayChannel,
            publish: new AmqpPublishConfig(exchange: self::EXCHANGE),
            contentType: AvroWireFormat::CONTENT_TYPE,
            lane: self::LANE,
        );
    }

    protected function tearDown(): void
    {
        $this->channel->close();
        $this->relayChannel->close();
    }

    private function consumer(?string $registryUrl = null): AmqpConsumer
    {
        $dispatch = function (IncomingMessage $incoming): void {
            ++$this->handlerAttempts;
            if ($this->handlerFails) {
                throw new RuntimeException('temporary downstream outage');
            }
            $this->dispatched[] = $incoming;
        };

        return new AmqpConsumer(
            channel: $this->channel,
            queue: new AmqpQueueConfig(self::QUEUE),
            deserializer: new AvroDeserializer(
                new HttpSchemaRegistry($registryUrl ?? self::registryUrl(), timeoutSec: 1.0),
            ),
            dispatcher: new CallableDispatcher($dispatch),
            pdo: self::$pdo,
            deduplication: new PdoDeduplicationStore(self::$pdo, $this->platform),
            retryPolicy: new AmqpRetryPolicy(
                maxAttempts: 2,
                backoff: Backoff::exponential(initialDelayMs: 100, maxDelayMs: 100),
            ),
            deadLetters: $this->deadLetters,
            name: 'avro_e2e_consumer',
        );
    }

    public function testHappyPathAvroFromProduceToDispatcher(): void
    {
        $message = OrderPlaced::create('o-42', 12_500);

        self::$pdo->beginTransaction();
        $this->producer->produce($message, headers: [
            'correlation_id' => 'corr-7',
        ]);
        self::$pdo->commit();

        // Outbox row body is the FINAL Confluent-framed Avro bytes (encode-at-produce).
        $row = self::$pdo->query('SELECT metadata, body FROM outbox_messages LIMIT 1')?->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'outbox row must exist');
        $frame = \Freyr\MessageBroker\Serializer\Avro\ConfluentFrame::parse(static::platform()->readBody($row['body']));
        self::assertGreaterThan(0, $frame->schemaId, 'body carries a Confluent frame with a registry id');
        $metadata = json_decode((string) $row['metadata'], true);
        self::assertSame($message->id, $metadata['message_id'], 'metadata column holds the envelope');
        self::assertSame('order.placed', $metadata['message_name']);

        self::assertSame(1, $this->relay->drainOnce());
        $this->consumer()
            ->run(messageLimit: 1, idleTimeoutSec: 10);

        self::assertCount(1, $this->dispatched);
        self::assertSame('o-42', $this->dispatched[0]->payload['order_id']);
        self::assertSame(12_500, $this->dispatched[0]->payload['total_cents']);
        self::assertSame($message->id, $this->dispatched[0]->messageId);
        self::assertSame($message->createdAt, $this->dispatched[0]->createdAt);

        // Produce-time headers survive alongside x-* library headers.
        self::assertSame('corr-7', $this->dispatched[0]->headers['correlation_id']);

        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'), 'outbox drained');
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM dead_letters'));
    }

    public function testFailureCircleStoresReadableDocumentAndReplays(): void
    {
        $message = OrderPlaced::create('o-911', 100);

        self::$pdo->beginTransaction();
        $this->producer->produce($message);
        self::$pdo->commit();

        self::assertSame(1, $this->relay->drainOnce());

        // Handler is broken: attempt 1 → 100ms wait queue → attempt 2 → DLQ.
        $this->handlerFails = true;
        $this->consumer()
            ->run(messageLimit: 2, idleTimeoutSec: 10);

        self::assertSame(2, $this->handlerAttempts);
        self::assertCount(0, $this->dispatched);

        $deadLetters = $this->deadLetters->list();
        self::assertCount(1, $deadLetters);
        self::assertSame($message->id, $deadLetters[0]->messageId);

        // REVIEW REQUIREMENT: DLQ'd body is a valid replayable document.
        $dlqDoc = json_decode($deadLetters[0]->body, true);
        self::assertIsArray($dlqDoc, 'dead letter body must be valid JSON');
        self::assertArrayHasKey('metadata', $dlqDoc, 'dead letter body must have metadata section');
        self::assertArrayHasKey('payload', $dlqDoc, 'dead letter body must have payload section');
        self::assertSame($message->id, $dlqDoc['metadata']['message_id'], 'metadata.message_id must match original');
        self::assertSame('o-911', $dlqDoc['payload']['order_id'], 'payload.order_id must survive round-trip');

        // Downstream recovered: replay rides the outbox + relay again.
        $this->handlerFails = false;
        $replay = new ReplayService($this->deadLetters, $this->outbox, new AvroWireFormat(
            $this->schemas,
            new HttpSchemaRegistry(self::registryUrl(), new ArrayCachePool())
        ));
        $replay->replay($deadLetters[0]->id, lane: self::LANE);

        self::assertSame(1, $this->relay->drainOnce());
        $this->consumer()
            ->run(messageLimit: 1, idleTimeoutSec: 10);

        // REVIEW REQUIREMENT: replayed message with the SAME message_id passes dedup.
        // (The failed attempt rolled back its dedup row, so dedup lets it through.)
        self::assertCount(1, $this->dispatched, 'replayed message must reach the fixed dispatcher');
        self::assertSame($message->id, $this->dispatched[0]->messageId);

        $replayed = $this->deadLetters->find($deadLetters[0]->id);
        self::assertNotNull($replayed);
        self::assertNotNull($replayed->replayedAt, 'dead letter kept for audit, marked replayed');
    }

    public function testRegistryOutagePropagatesAndNeverDeadLetters(): void
    {
        $message = OrderPlaced::create('o-outage', 50);

        self::$pdo->beginTransaction();
        $this->producer->produce($message);
        self::$pdo->commit();

        // Drain with the GOOD registry so the message is in AMQP.
        self::assertSame(1, $this->relay->drainOnce());

        // Consume with a broken registry URL — RegistryUnavailable must propagate.
        // tearDown closes the channel, requeuing the unacked delivery; setUp's
        // queue_purge cleans it for the next test.
        try {
            $this->consumer(registryUrl: 'http://schema-registry:9')
                ->run(messageLimit: 1, idleTimeoutSec: 10);
            self::fail('RegistryUnavailable was expected to propagate out of run()');
        } catch (RegistryUnavailable) {
            // expected — delivery stays unacked and requeues on channel close
        }

        self::assertCount(0, $this->dispatched);
        self::assertSame(
            0,
            self::fetchInt('SELECT COUNT(*) FROM dead_letters'),
            'registry outage must never mass-DLQ valid messages (A10)',
        );
    }

    public function testUnregisteredSchemaFailsAtProduceThenSucceedsAfterRegistration(): void
    {
        $producer = new OutboxProducer(
            $this->outbox,
            // Fresh registry instance + cold ArrayCachePool: a SchemaNotFound
            // is not cached, so the retry after registration looks it up again.
            new AvroWireFormat($this->schemas, new HttpSchemaRegistry(self::registryUrl(), new ArrayCachePool())),
            lane: 'avro_unregistered',
        );

        // 'order.never_registered' has a committed .avsc (payload encodes) but
        // is NOT registered — produce throws SchemaNotFound, inside the txn.
        self::$pdo->beginTransaction();
        try {
            $producer->produce(NeverRegistered::create());
            self::fail('produce must throw for an unregistered subject');
        } catch (SchemaNotFound) {
            // expected — the encode validated the payload, the id lookup 404'd
        }
        self::$pdo->rollBack();

        self::assertSame(
            0,
            self::fetchInt("SELECT COUNT(*) FROM outbox_messages WHERE lane = 'avro_unregistered'"),
            'a non-publishable message never commits (E5/D17)',
        );

        // Play the CI registration role, then produce succeeds.
        $schemaJson = file_get_contents(self::SCHEMA_PATH);
        self::assertNotFalse($schemaJson);
        self::registerSchema('order.never_registered', $schemaJson);

        self::$pdo->beginTransaction();
        $producer->produce(NeverRegistered::create());
        self::$pdo->commit();

        self::assertSame(
            1,
            self::fetchInt("SELECT COUNT(*) FROM outbox_messages WHERE lane = 'avro_unregistered'"),
            'after registration the row commits',
        );
    }

    public function testProduceTimeRegistryOutageFailsTheBusinessTransactionCleanly(): void
    {
        $producer = new OutboxProducer(
            $this->outbox,
            // Registry pointed at a dead port + cold cache: the id lookup fails.
            new AvroWireFormat($this->schemas, new HttpSchemaRegistry(
                'http://schema-registry:9',
                new ArrayCachePool(),
                timeoutSec: 1.0
            )),
            lane: self::LANE,
        );

        self::$pdo->beginTransaction();
        try {
            $producer->produce(OrderPlaced::create('o-cold', 1));
            self::fail('RegistryUnavailable was expected on the cold produce path');
        } catch (RegistryUnavailable) {
            // expected — cold path needs the registry; steady state never does
        }
        self::$pdo->rollBack();

        self::assertSame(
            0,
            self::fetchInt('SELECT COUNT(*) FROM outbox_messages'),
            'nothing commits on a registry outage'
        );
    }
}
