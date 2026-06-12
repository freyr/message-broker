<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Avro;

use Freyr\MessageBroker\Consumer\Binding;
use Freyr\MessageBroker\Consumer\HandlerRegistry;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Consumer\PdoDeduplicationStore;
use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\DeadLetter\ReplayService;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\OutboxProducer;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Serializer\Avro\AvroDeserializer;
use Freyr\MessageBroker\Serializer\Avro\AvroSerializer;
use Freyr\MessageBroker\Serializer\Avro\AvroWireValidator;
use Freyr\MessageBroker\Serializer\Avro\FileSchemaStore;
use Freyr\MessageBroker\Serializer\Avro\HttpSchemaRegistry;
use Freyr\MessageBroker\Serializer\Avro\RegistryUnavailable;
use Freyr\MessageBroker\Storage\MySqlPlatform;
use Freyr\MessageBroker\Tests\Fixtures\NeverRegistered;
use Freyr\MessageBroker\Tests\Fixtures\OrderPlaced;
use Freyr\MessageBroker\Tests\Fixtures\OrderPlacedDto;
use Freyr\MessageBroker\Tests\Fixtures\RecordingErrorHandler;
use Freyr\MessageBroker\Tests\Functional\FunctionalTestCase;
use Freyr\MessageBroker\Transport\Amqp\AmqpConsumer;
use Freyr\MessageBroker\Transport\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpQueueConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpRelay;
use Freyr\MessageBroker\Transport\Amqp\AmqpRetryPolicy;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use RuntimeException;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * The slice 2 promise: produce (Avro-validated) → outbox (JSON document) →
 * relay (Confluent-framed Avro + x-* headers) → RabbitMQ → consumer
 * (registry-backed decode) → typed handler — plus the failure circle
 * (DLQ stores a readable, replayable document) and both operational
 * failure modes (registry down; schema never registered).
 */
final class AvroEndToEndTest extends FunctionalTestCase
{
    use RegistersSchemas;

    private const string EXCHANGE = 'mb_avro_e2e';
    private const string QUEUE = 'mb_avro_e2e_q';
    private const string LANE = 'avro_e2e';
    private const string SCHEMA_PATH = __DIR__.'/../../Fixtures/schemas/order_placed.avsc';

    private static AMQPStreamConnection $amqp;

    private AMQPChannel $channel;
    private AMQPChannel $relayChannel;
    private MySqlPlatform $platform;
    private OutboxStore $outbox;
    private OutboxProducer $producer;
    private PdoDeadLetterStore $deadLetters;
    private AmqpRelay $relay;
    private FileSchemaStore $schemas;

    private bool $handlerFails = false;
    private int $handlerAttempts = 0;

    /** @var list<array{message: OrderPlacedDto, envelope: IncomingMessage}> */
    private array $handled = [];

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
        $channel->queue_delete('mb_avro_unregistered_q');
        $channel->queue_delete('mb_avro_unregistered_q.wait.100');
        $channel->exchange_delete(self::EXCHANGE);
        $channel->exchange_delete('mb_avro_unregistered');
        $channel->close();
        self::$amqp->close();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->handled = [];
        $this->handlerFails = false;
        $this->handlerAttempts = 0;

        $this->channel = self::$amqp->channel();
        $this->relayChannel = self::$amqp->channel();
        $this->channel->exchange_declare(self::EXCHANGE, 'topic', false, true, false);
        $this->channel->queue_declare(self::QUEUE, false, true, false, false);
        $this->channel->queue_bind(self::QUEUE, self::EXCHANGE, 'order.*');
        $this->channel->queue_purge(self::QUEUE);

        $this->platform = new MySqlPlatform();
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
            lane: self::LANE,
            validator: new AvroWireValidator($this->schemas),
        );

        $this->relay = new AmqpRelay(
            outbox: $this->outbox,
            amqp: $this->relayChannel,
            publish: new AmqpPublishConfig(exchange: self::EXCHANGE),
            serializer: new AvroSerializer($this->schemas, new HttpSchemaRegistry(self::registryUrl())),
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
        $handler = function (OrderPlacedDto $message, IncomingMessage $envelope): void {
            ++$this->handlerAttempts;
            if ($this->handlerFails) {
                throw new RuntimeException('temporary downstream outage');
            }
            $this->handled[] = [
                'message' => $message,
                'envelope' => $envelope,
            ];
        };

        return new AmqpConsumer(
            channel: $this->channel,
            queue: new AmqpQueueConfig(self::QUEUE),
            deserializer: new AvroDeserializer(
                new HttpSchemaRegistry($registryUrl ?? self::registryUrl(), timeoutSec: 1.0),
            ),
            handlers: new HandlerRegistry(
                bindings: [
                    'order.placed' => new Binding(OrderPlacedDto::class, $handler),
                ],
                denormalizer: new Serializer([
                    new ObjectNormalizer(nameConverter: new CamelCaseToSnakeCaseNameConverter()),
                ]),
            ),
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

    public function testHappyPathAvroFromProduceToTypedHandler(): void
    {
        $message = OrderPlaced::create('o-42', 12_500);

        self::$pdo->beginTransaction();
        $this->producer->produce($message, headers: [
            'correlation_id' => 'corr-7',
        ]);
        self::$pdo->commit();

        // Outbox row body is a JSON document (encode-at-relay proof — raw Avro lives only in AMQP).
        $outboxBody = self::$pdo->query('SELECT body FROM outbox_messages LIMIT 1')?->fetchColumn();
        self::assertIsString($outboxBody, 'outbox row must exist');
        $decoded = json_decode($outboxBody, true);
        self::assertIsArray($decoded, 'outbox body must be valid JSON');
        self::assertArrayHasKey('payload', $decoded, 'outbox body must contain payload section');
        self::assertArrayHasKey('metadata', $decoded, 'outbox body must contain metadata section');

        self::assertSame(1, $this->relay->drainOnce());
        $this->consumer()
            ->run(messageLimit: 1, idleTimeoutSec: 10);

        self::assertCount(1, $this->handled);
        self::assertSame('o-42', $this->handled[0]['message']->orderId);
        self::assertSame(12_500, $this->handled[0]['message']->totalCents);
        self::assertSame($message->id, $this->handled[0]['envelope']->messageId);
        self::assertSame($message->createdAt, $this->handled[0]['envelope']->createdAt);

        // Produce-time headers survive alongside x-* library headers.
        self::assertSame('corr-7', $this->handled[0]['envelope']->headers['correlation_id']);

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
        self::assertCount(0, $this->handled);

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
        $replay = new ReplayService($this->deadLetters, $this->outbox);
        $replay->replay($deadLetters[0]->id, lane: self::LANE);

        self::assertSame(1, $this->relay->drainOnce());
        $this->consumer()
            ->run(messageLimit: 1, idleTimeoutSec: 10);

        // REVIEW REQUIREMENT: replayed message with the SAME message_id passes dedup.
        // (The failed attempt rolled back its dedup row, so dedup lets it through.)
        self::assertCount(1, $this->handled, 'replayed message must reach the fixed handler');
        self::assertSame($message->id, $this->handled[0]['envelope']->messageId);

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
        $this->expectException(RegistryUnavailable::class);

        $this->consumer(registryUrl: 'http://apicurio:9')
            ->run(messageLimit: 1, idleTimeoutSec: 10);

        // The assertions below are unreachable when the exception propagates,
        // but the exception itself proves no dead-lettering happened.
        // tearDown closes the channel, requeuing the unacked delivery; setUp's
        // queue_purge cleans it for the next test.
    }

    /**
     * @doesNotPerformAssertions
     * See inline assertSame calls — PHPUnit counts them.
     */
    public function testUnregisteredSchemaBlocksLaneWithAlertNotLoss(): void
    {
        // Separate lane so the blocked head cannot interfere with the main lane.
        $unregisteredLane = 'avro_unregistered';
        $unregisteredExchange = 'mb_avro_unregistered';
        $unregisteredQueue = 'mb_avro_unregistered_q';

        $unregisteredRelayChannel = self::$amqp->channel();
        $unregisteredRelayChannel->exchange_declare($unregisteredExchange, 'topic', false, true, false);
        $unregisteredRelayChannel->queue_declare($unregisteredQueue, false, true, false, false);
        $unregisteredRelayChannel->queue_bind($unregisteredQueue, $unregisteredExchange, 'order.*');
        $unregisteredRelayChannel->queue_purge($unregisteredQueue);

        $unregisteredOutbox = new OutboxStore(self::$pdo, $this->platform);

        $errorHandler = new RecordingErrorHandler();

        $unregisteredRelay = new AmqpRelay(
            outbox: $unregisteredOutbox,
            amqp: $unregisteredRelayChannel,
            publish: new AmqpPublishConfig(exchange: $unregisteredExchange),
            serializer: new AvroSerializer(
                $this->schemas,
                // Fresh registry instance — no cache from the main tests.
                new HttpSchemaRegistry(self::registryUrl()),
            ),
            lane: $unregisteredLane,
            errorHandler: $errorHandler,
        );

        $unregisteredProducer = new OutboxProducer(
            $unregisteredOutbox,
            lane: $unregisteredLane,
            validator: new AvroWireValidator($this->schemas),
        );

        // Locally valid — the validator passes because the .avsc file exists.
        // The subject 'order.never_registered' is NOT registered in the registry.
        self::$pdo->beginTransaction();
        $unregisteredProducer->produce(NeverRegistered::create());
        self::$pdo->commit();

        // D17: relay backs off the head — 0 rows published, alert surfaced.
        self::assertSame(0, $unregisteredRelay->drainOnce(), 'relay must block, not publish unregistered schema');

        // The outbox row is preserved — no data loss.
        $laneCount = self::fetchInt("SELECT COUNT(*) FROM outbox_messages WHERE lane = '{$unregisteredLane}'");
        self::assertSame(1, $laneCount, 'outbox row must remain for the blocked lane');

        // The error handler must have been called to surface the alert.
        self::assertGreaterThanOrEqual(1, count($errorHandler->calls), 'error handler must record the relay failure');

        // Attempts counter must be 1 after one blocked drain.
        $attempts = self::fetchInt(
            "SELECT attempts FROM outbox_messages WHERE lane = '{$unregisteredLane}' LIMIT 1",
        );
        self::assertSame(1, $attempts, 'head must be backed off with attempts = 1');

        $unregisteredRelayChannel->close();
    }
}
