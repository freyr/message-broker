<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Outbox\LaneLimits;
use Freyr\MessageBroker\Outbox\MessageTooLarge;
use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxStore;
use Freyr\MessageBroker\Testing\OrderPlaced;
use Freyr\MessageBroker\Testing\RecordingEvents;
use Freyr\MessageBroker\Testing\Unserializable;
use InvalidArgumentException;
use JsonException;
use PDO;

final class OutboxProduceTest extends FunctionalTestCase
{
    private OutboxProducer $producer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->producer = new OutboxProducer(
            store: new PostgreSqlOutboxStore(self::$pdo),
            wireFormat: new JsonWireFormat(),
            lane: 'orders',
        );
    }

    public function testProduceInsertsUnpublishedRowWithEncodedBody(): void
    {
        $message = OrderPlaced::create('o-123', 4999);

        $this->producer->produce($message, headers: [
            'correlation_id' => 'c-1',
        ]);

        $row = self::$pdo->query('SELECT * FROM outbox_messages')->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($row, 'expected one outbox row');
        self::assertSame($message->id, $row['id']);
        self::assertSame('orders', $row['lane']);
        self::assertSame('order.placed', $row['message_name']);
        self::assertSame(0, (int) $row['attempts']);
        self::assertNull($row['published_at'], 'a new row waits for the relay');
        self::assertSame(
            $message->createdAt,
            (int) (new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')))->format('Uv'),
        );

        // body is the payload only (JSON format).
        self::assertSame([
            'order_id' => 'o-123',
            'total_cents' => 4999,
        ], json_decode(self::bodyOf($row['body']), true, flags: JSON_THROW_ON_ERROR));

        self::assertSame([
            'correlation_id' => 'c-1',
        ], json_decode((string) $row['headers'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testProducerRejectsAnOverLongLaneWithAClearError(): void
    {
        $producer = new OutboxProducer(
            store: new PostgreSqlOutboxStore(self::$pdo),
            wireFormat: new JsonWireFormat(),
            lane: str_repeat('l', 65),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Outbox lane must be 1 to 64 characters');

        $producer->produce(OrderPlaced::create('o-1', 100));
    }

    public function testProduceJoinsTheApplicationTransaction(): void
    {
        self::$pdo->beginTransaction();
        $this->producer->produce(OrderPlaced::create('o-9', 100));
        self::$pdo->rollBack();

        self::assertSame(0, (int) self::$pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
    }

    public function testARefusedMessageInsideAnApplicationTransactionLeavesNoRow(): void
    {
        $producer = new OutboxProducer(
            store: new PostgreSqlOutboxStore(self::$pdo),
            wireFormat: new JsonWireFormat(),
            lane: 'orders',
            limits: new LaneLimits(maxBodyBytes: 40),
        );

        self::$pdo->beginTransaction();
        $producer->produce(OrderPlaced::create('o-1', 1));
        try {
            $producer->produce(OrderPlaced::create(str_repeat('x', 20), 1));
            self::fail('expected MessageTooLarge');
        } catch (MessageTooLarge $e) {
            self::assertSame('body', $e->part);
            self::assertSame(51, $e->size);
            self::assertSame(40, $e->limit);
        }
        self::assertSame(1, (int) self::$pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn(), 'the refused message inserted no row');
        self::$pdo->rollBack();

        self::assertSame(0, (int) self::$pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
    }

    public function testProduceRejectsUnserializablePayloadBeforeInsert(): void
    {
        try {
            $this->producer->produce(Unserializable::create());
            self::fail('expected produce() to throw');
        } catch (InvalidArgumentException|JsonException) {
            // poison stopped at the door (D17)
        }

        self::assertSame(0, (int) self::$pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
    }

    public function testProduceFiresProducedEvent(): void
    {
        $events = new RecordingEvents();
        $producer = new OutboxProducer(
            store: new PostgreSqlOutboxStore(self::$pdo),
            wireFormat: new JsonWireFormat(),
            lane: 'orders',
            events: $events,
        );
        $message = OrderPlaced::create('o-7', 100);

        $producer->produce($message);

        self::assertContains(BrokerEvents::PRODUCED, $events->names());
        self::assertSame($message->id, $events->records[0]['context']['message_id']);
        self::assertSame([
            'lane' => 'orders',
            'message_id' => $message->id,
            'message_name' => 'order.placed',
        ], $events->records[0]['context']);
    }
}
