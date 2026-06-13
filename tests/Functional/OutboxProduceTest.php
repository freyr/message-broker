<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Storage\MySqlPlatform;
use Freyr\MessageBroker\Tests\Fixtures\OrderPlaced;
use Freyr\MessageBroker\Tests\Fixtures\Unserializable;
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
            store: new OutboxStore(self::$pdo, new MySqlPlatform()),
            wireFormat: new JsonWireFormat(),
            lane: 'orders',
        );
    }

    public function testProduceInsertsRowWithEncodedBodyAndMetadata(): void
    {
        $message = OrderPlaced::create('o-123', 4999);

        $this->producer->produce($message, headers: [
            'correlation_id' => 'c-1',
        ]);

        $row = self::$pdo->query('SELECT * FROM outbox_messages')->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($row, 'expected one outbox row');
        self::assertSame($message->id, $row['id']);
        self::assertSame('orders', $row['lane']);
        self::assertSame('o-123', $row['message_key']);
        self::assertSame(
            'order.placed',
            $row['message_name'],
            'message_name column mirrors metadata.message_name (for Debezium stock x-message-name mapping)'
        );

        // body is the payload only (JSON format).
        self::assertSame([
            'order_id' => 'o-123',
            'total_cents' => 4999,
        ], json_decode((string) $row['body'], true, flags: JSON_THROW_ON_ERROR));

        // metadata column holds the envelope.
        $metadata = json_decode((string) $row['metadata'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($message->id, $metadata['message_id']);
        self::assertSame('order.placed', $metadata['message_name']);
        self::assertSame($message->createdAt, $metadata['created_at']);

        self::assertSame([
            'correlation_id' => 'c-1',
        ], json_decode((string) $row['headers'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testProduceJoinsTheApplicationTransaction(): void
    {
        self::$pdo->beginTransaction();
        $this->producer->produce(OrderPlaced::create('o-9', 100));
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
}
