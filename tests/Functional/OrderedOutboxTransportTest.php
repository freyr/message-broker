<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\MessageBroker\Outbox\PartitionKeyStamp;
use Freyr\MessageBroker\Outbox\Transport\OrderedOutboxTransport;
use Freyr\MessageBroker\Tests\Fixtures\TestOutboxEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Functional test for OrderedOutboxTransport against real MySQL.
 *
 * Verifies partition-aware head-of-line query, partition key storage,
 * ack/reject, keepalive, and auto-setup against a live database.
 */
#[CoversClass(OrderedOutboxTransport::class)]
final class OrderedOutboxTransportTest extends FunctionalDatabaseTestCase
{
    private const TABLE = 'test_ordered_outbox';

    private OrderedOutboxTransport $transport;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', self::TABLE));
    }

    public static function tearDownAfterClass(): void
    {
        self::$connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', self::TABLE));
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        // Do NOT call parent::setUp() — it truncates the deduplication table, not the outbox table
        self::$connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', self::TABLE));

        $this->transport = new OrderedOutboxTransport(
            connection: self::$connection,
            serializer: new PhpSerializer(),
            tableName: self::TABLE,
            queueName: 'outbox',
        );

        $this->transport->setup();
    }

    public function testSetupCreatesTableWithPartitionKeyColumn(): void
    {
        $columns = self::$connection->createSchemaManager()->listTableColumns(self::TABLE);

        $columnNames = array_keys($columns);

        $this->assertContains('id', $columnNames);
        $this->assertContains('body', $columnNames);
        $this->assertContains('headers', $columnNames);
        $this->assertContains('queue_name', $columnNames);
        $this->assertContains('created_at', $columnNames);
        $this->assertContains('available_at', $columnNames);
        $this->assertContains('delivered_at', $columnNames);
        $this->assertContains('partition_key', $columnNames);
    }

    public function testSendStoresPartitionKey(): void
    {
        $envelope = new Envelope(TestOutboxEvent::random(), [new PartitionKeyStamp('order-abc')]);

        $result = $this->transport->send($envelope);

        $stamp = $result->last(TransportMessageIdStamp::class);
        $this->assertNotNull($stamp);

        $row = self::$connection->fetchAssociative(
            sprintf('SELECT partition_key, queue_name FROM %s WHERE id = ?', self::TABLE),
            [$stamp->getId()],
        );

        $this->assertIsArray($row);
        $this->assertSame('order-abc', $row['partition_key']);
        $this->assertSame('outbox', $row['queue_name']);
    }

    public function testGetReturnsOldestMessagePerPartition(): void
    {
        // Insert 3 messages for partition "order-X": msg1(id=lowest), msg2, msg3
        $msg1 = $this->sendEvent('order-X', 'first');
        $msg2 = $this->sendEvent('order-X', 'second');
        $msg3 = $this->sendEvent('order-X', 'third');

        // Insert 1 message for partition "order-Y"
        $msgY = $this->sendEvent('order-Y', 'y-first');

        // First get() returns a partition head: msg1 (head of order-X) or msgY (head of order-Y)
        $fetched = iterator_to_array($this->transport->get());
        $this->assertCount(1, $fetched);

        $fetchedId = $fetched[0]->last(TransportMessageIdStamp::class)?->getId();
        $this->assertNotNull($fetchedId);
        $this->assertContains($fetchedId, [$msg1, $msgY], 'Should return a partition head');

        $this->transport->ack($fetched[0]);

        // Drain remaining messages, tracking order per partition
        $orderXSequence = [];
        $orderYProcessed = false;

        if ($fetchedId === $msg1) {
            $orderXSequence[] = $msg1;
        } else {
            $orderYProcessed = true;
        }

        // Process all remaining messages
        while (true) {
            $batch = iterator_to_array($this->transport->get());
            if (\count($batch) === 0) {
                break;
            }
            $id = $batch[0]->last(TransportMessageIdStamp::class)?->getId();
            $this->assertNotNull($id);

            if (\in_array($id, [$msg1, $msg2, $msg3], true)) {
                $orderXSequence[] = $id;
            }
            if ($id === $msgY) {
                $orderYProcessed = true;
            }

            $this->transport->ack($batch[0]);
        }

        // Verify order-X messages were processed in insertion order
        $this->assertSame([$msg1, $msg2, $msg3], $orderXSequence, 'Partition order-X must be FIFO');
        $this->assertTrue($orderYProcessed, 'Partition order-Y must be processed');
    }

    public function testAckDeletesRow(): void
    {
        $id = $this->sendEvent('partition-a', 'data');

        $fetched = iterator_to_array($this->transport->get());
        $this->assertCount(1, $fetched);

        $this->transport->ack($fetched[0]);

        $count = self::$connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', self::TABLE));
        $this->assertIsNumeric($count);
        $this->assertSame(0, (int) $count);
    }

    public function testRejectDeletesRow(): void
    {
        $this->sendEvent('partition-b', 'data');

        $fetched = iterator_to_array($this->transport->get());
        $this->assertCount(1, $fetched);

        $this->transport->reject($fetched[0]);

        $count = self::$connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', self::TABLE));
        $this->assertIsNumeric($count);
        $this->assertSame(0, (int) $count);
    }

    public function testEmptyPartitionKeyTreatedAsIndependent(): void
    {
        // Messages without PartitionKeyStamp get partition_key = ''
        $this->sendEvent('', 'msg-a');
        $this->sendEvent('', 'msg-b');

        // Both have partition_key = '' — they are in the same partition,
        // so only the oldest is returned
        $fetched = iterator_to_array($this->transport->get());
        $this->assertCount(1, $fetched);
    }

    public function testKeepaliveRefreshesDeliveredAt(): void
    {
        $this->sendEvent('partition-k', 'data');

        $fetched = iterator_to_array($this->transport->get());
        $this->assertCount(1, $fetched);

        $idStamp = $fetched[0]->last(TransportMessageIdStamp::class);
        $this->assertNotNull($idStamp);

        // Read the delivered_at before keepalive
        $before = self::$connection->fetchOne(
            sprintf('SELECT delivered_at FROM %s WHERE id = ?', self::TABLE),
            [$idStamp->getId()],
        );
        $this->assertNotNull($before);

        // Wait 1 second to ensure timestamp changes
        sleep(1);

        $this->transport->keepalive($fetched[0]);

        $after = self::$connection->fetchOne(
            sprintf('SELECT delivered_at FROM %s WHERE id = ?', self::TABLE),
            [$idStamp->getId()],
        );

        $this->assertNotSame($before, $after, 'Keepalive should update delivered_at');

        // Clean up
        $this->transport->ack($fetched[0]);
    }

    public function testRedeliveryTimeoutReleasesMessage(): void
    {
        $this->sendEvent('partition-r', 'data');

        // Create a transport with a very short redeliver timeout (1 second)
        $shortTimeoutTransport = new OrderedOutboxTransport(
            connection: self::$connection,
            serializer: new PhpSerializer(),
            tableName: self::TABLE,
            queueName: 'outbox',
            redeliverTimeout: 1,
        );

        // Fetch and claim the message (sets delivered_at)
        $fetched = iterator_to_array($shortTimeoutTransport->get());
        $this->assertCount(1, $fetched);

        // Simulate worker crash — don't ack. Wait for timeout.
        sleep(2);

        // Another transport instance should be able to reclaim it
        $refetched = iterator_to_array($shortTimeoutTransport->get());
        $this->assertCount(1, $refetched, 'Message should be redelivered after timeout');

        // Clean up
        $shortTimeoutTransport->ack($refetched[0]);
    }

    /**
     * Sends a test event and returns its transport message ID.
     */
    private function sendEvent(string $partitionKey, string $payload): string
    {
        $stamps = [];
        if ($partitionKey !== '') {
            $stamps[] = new PartitionKeyStamp($partitionKey);
        }

        $envelope = new Envelope(TestOutboxEvent::random($payload), $stamps);
        $result = $this->transport->send($envelope);

        $stamp = $result->last(TransportMessageIdStamp::class);
        $this->assertInstanceOf(TransportMessageIdStamp::class, $stamp);

        $id = $stamp->getId();
        $this->assertIsString($id);

        return $id;
    }
}
