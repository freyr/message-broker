<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Avro;

use Freyr\MessageBroker\Consumer\CallableDispatcher;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\DeadLetter\ReplayService;
use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Outbox\PdoOutboxStore;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Serializer\Avro\AvroDeserializer;
use Freyr\MessageBroker\Serializer\Avro\AvroWireFormat;
use Freyr\MessageBroker\Serializer\Avro\ConfluentFrame;
use Freyr\MessageBroker\Serializer\Avro\DerivedSchemaId;
use Freyr\MessageBroker\Serializer\Avro\FileSchemaRegistry;
use Freyr\MessageBroker\Serializer\Avro\FileSchemaStore;
use Freyr\MessageBroker\Serializer\Format;
use Freyr\MessageBroker\Storage\Platform;
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
 * The Avro promise (registry-free, encode-at-produce): produce (Avro encoded at
 * the door → outbox `body` = Confluent-framed bytes with a schema id DERIVED
 * from the committed schema, envelope in the id, message_name and created_at columns) → relay
 * (byte-pump: verbatim body + individual x-message-* headers) → RabbitMQ →
 * consumer (resolves the writer schema by id against its own reader schema by
 * message name — filling reader defaults) → dispatcher. Plus the failure circle
 * (DLQ stores a readable, replayable document) and cross-version resolution
 * (a v1-written frame decodes against a v2 reader).
 */
final class AvroEndToEndTest extends FunctionalTestCase
{
    private const string EXCHANGE = 'mb_avro_e2e';
    private const string QUEUE = 'mb_avro_e2e_q';
    private const string LANE = 'avro_e2e';
    private const string V1_PATH = __DIR__.'/../../Fixtures/schemas/order.placed/v1.avsc';
    private const string V2_PATH = __DIR__.'/../../Fixtures/schemas/order.placed/v2.avsc';

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
    private FileSchemaStore $producerSchemas;
    private FileSchemaRegistry $registry;

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
    }

    public static function tearDownAfterClass(): void
    {
        $channel = self::$amqp->channel();
        $channel->queue_delete(self::QUEUE);
        $channel->queue_delete(self::QUEUE.'.wait.100');
        $channel->exchange_delete(self::EXCHANGE);
        $channel->close();
        self::$amqp->close();
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
        $this->outbox = new PdoOutboxStore(self::$pdo, $this->platform);
        $this->deadLetters = new PdoDeadLetterStore(self::$pdo, $this->platform);

        // The producer encodes with the current committed schema (v1); the
        // registry-free resolver holds EVERY committed version (v1, v2) so a
        // consumer can decode either.
        $this->producerSchemas = new FileSchemaStore([
            'order.placed' => self::V1_PATH,
        ]);
        $this->registry = new FileSchemaRegistry([self::V1_PATH, self::V2_PATH]);

        $this->producer = new OutboxProducer(
            $this->outbox,
            new AvroWireFormat($this->producerSchemas, $this->registry),
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

    /** @param non-empty-string $readerSchemaPath the consumer's reader schema for order.placed */
    private function consumer(string $readerSchemaPath = self::V1_PATH): AmqpConsumer
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
                $this->registry,
                new FileSchemaStore([
                    'order.placed' => $readerSchemaPath,
                ]),
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

        // Outbox row body is the FINAL Confluent-framed Avro bytes; the id is
        // derived from the committed schema, not registry-assigned.
        $row = self::$pdo->query('SELECT id, message_name, body FROM outbox_messages LIMIT 1')?->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'outbox row must exist');
        $frame = ConfluentFrame::parse(static::platform()->readBody($row['body']));
        $v1Json = file_get_contents(self::V1_PATH);
        self::assertIsString($v1Json);
        self::assertSame(DerivedSchemaId::fromSchemaJson($v1Json), $frame->schemaId, 'body carries the derived id');
        self::assertSame($message->id, $row['id'], 'the row columns hold the envelope');
        self::assertSame('order.placed', $row['message_name']);

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

        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM outbox_messages WHERE published_at IS NULL'), 'outbox drained');
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM dead_letters'));
    }

    public function testCrossVersionFrameDecodesAgainstNewerReaderFillingDefaults(): void
    {
        // Producer still on v1; this consumer already upgraded to v2 (adds
        // `currency` with a "USD" default). Rolling-deploy forward compat.
        $message = OrderPlaced::create('o-v1', 999);

        self::$pdo->beginTransaction();
        $this->producer->produce($message);
        self::$pdo->commit();

        self::assertSame(1, $this->relay->drainOnce());
        $this->consumer(readerSchemaPath: self::V2_PATH)
            ->run(messageLimit: 1, idleTimeoutSec: 10);

        self::assertCount(1, $this->dispatched);
        self::assertSame('o-v1', $this->dispatched[0]->payload['order_id']);
        self::assertSame(999, $this->dispatched[0]->payload['total_cents']);
        self::assertSame('USD', $this->dispatched[0]->payload['currency'], 'reader default filled for the new field');
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

        // DLQ'd body is a valid replayable document.
        $dlqDoc = json_decode($deadLetters[0]->body, true);
        self::assertIsArray($dlqDoc, 'dead letter body must be valid JSON');
        self::assertArrayHasKey('metadata', $dlqDoc, 'dead letter body must have metadata section');
        self::assertArrayHasKey('payload', $dlqDoc, 'dead letter body must have payload section');
        self::assertSame($message->id, $dlqDoc['metadata']['message_id'], 'metadata.message_id must match original');
        self::assertSame('o-911', $dlqDoc['payload']['order_id'], 'payload.order_id must survive round-trip');

        // Downstream recovered: replay rides the outbox + relay again.
        $this->handlerFails = false;
        $replay = new ReplayService(
            $this->deadLetters,
            $this->outbox,
            new AvroWireFormat($this->producerSchemas, $this->registry),
        );
        $replay->replay($deadLetters[0]->id, lane: self::LANE);

        self::assertSame(1, $this->relay->drainOnce());
        $this->consumer()
            ->run(messageLimit: 1, idleTimeoutSec: 10);

        // Replayed message with the SAME message_id passes dedup.
        // (The failed attempt rolled back its dedup row, so dedup lets it through.)
        self::assertCount(1, $this->dispatched, 'replayed message must reach the fixed dispatcher');
        self::assertSame($message->id, $this->dispatched[0]->messageId);

        $replayed = $this->deadLetters->find($deadLetters[0]->id);
        self::assertNotNull($replayed);
        self::assertNotNull($replayed->replayedAt, 'dead letter kept for audit, marked replayed');
    }
}
