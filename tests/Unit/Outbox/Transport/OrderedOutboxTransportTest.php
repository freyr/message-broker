<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Outbox\Transport;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Freyr\MessageBroker\Outbox\PartitionKeyStamp;
use Freyr\MessageBroker\Outbox\Transport\OrderedOutboxTransport;
use Freyr\MessageBroker\Tests\Fixtures\TestOutboxEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

#[CoversClass(OrderedOutboxTransport::class)]
final class OrderedOutboxTransportTest extends TestCase
{
    private Connection&MockObject $connection;
    private SerializerInterface&MockObject $serializer;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
    }

    public function testSendExtractsPartitionKeyAndInsertsRow(): void
    {
        $event = TestOutboxEvent::random();
        $envelope = new Envelope($event, [new PartitionKeyStamp('order-abc')]);

        $this->serializer->method('encode')
            ->willReturn([
                'body' => '{"payload":"Test"}',
                'headers' => [
                    'type' => 'test.event.sent',
                ],
            ]);

        $this->connection->expects($this->once())
            ->method('insert')
            ->with(
                'messenger_outbox',
                $this->callback(function (array $data): bool {
                    $this->assertSame('order-abc', $data['partition_key']);
                    $this->assertSame('{"payload":"Test"}', $data['body']);
                    $this->assertSame('outbox', $data['queue_name']);
                    $this->assertArrayHasKey('created_at', $data);
                    $this->assertArrayHasKey('available_at', $data);

                    return true;
                }),
                $this->anything(),
            );

        $this->connection->method('lastInsertId')
            ->willReturn('42');

        $transport = $this->createTransport();
        $result = $transport->send($envelope);

        $stamp = $result->last(TransportMessageIdStamp::class);
        $this->assertNotNull($stamp);
        $this->assertSame('42', $stamp->getId());
    }

    public function testSendDefaultsToEmptyPartitionKeyWhenStampMissing(): void
    {
        $envelope = new Envelope(TestOutboxEvent::random());

        $this->serializer->method('encode')
            ->willReturn([
                'body' => '{}',
                'headers' => [],
            ]);

        $this->connection->expects($this->once())
            ->method('insert')
            ->with(
                'messenger_outbox',
                $this->callback(fn (array $data): bool => $data['partition_key'] === ''),
                $this->anything(),
            );

        $this->connection->method('lastInsertId')
            ->willReturn('1');

        $transport = $this->createTransport();
        $transport->send($envelope);
    }

    public function testGetReturnsEnvelopeWhenMessageAvailable(): void
    {
        $event = TestOutboxEvent::random();
        $envelope = new Envelope($event);

        $this->connection->expects($this->once())
            ->method('beginTransaction');
        $this->connection->expects($this->once())
            ->method('commit');

        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')
            ->willReturn([
                'id' => '7',
                'body' => '{"payload":"Hello"}',
                'headers' => '{"type":"test.event.sent"}',
            ]);

        $this->connection->method('executeQuery')
            ->willReturn($result);
        $this->connection->expects($this->once())
            ->method('update')
            ->with('messenger_outbox', $this->anything(), [
                'id' => '7',
            ], $this->anything());

        $this->serializer->method('decode')
            ->willReturn($envelope);

        $transport = $this->createTransport();
        $envelopes = iterator_to_array($transport->get());

        $this->assertCount(1, $envelopes);
        $stamp = $envelopes[0]->last(TransportMessageIdStamp::class);
        $this->assertNotNull($stamp);
        $this->assertSame('7', $stamp->getId());
    }

    public function testGetReturnsEmptyWhenNoMessagesAvailable(): void
    {
        $this->connection->expects($this->once())
            ->method('beginTransaction');
        $this->connection->expects($this->once())
            ->method('commit');

        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')
            ->willReturn(false);

        $this->connection->method('executeQuery')
            ->willReturn($result);

        $transport = $this->createTransport();
        $envelopes = iterator_to_array($transport->get());

        $this->assertCount(0, $envelopes);
    }

    public function testAckDeletesRowById(): void
    {
        $envelope = new Envelope(TestOutboxEvent::random(), [new TransportMessageIdStamp('99')]);

        $this->connection->expects($this->once())
            ->method('delete')
            ->with('messenger_outbox', [
                'id' => '99',
            ]);

        $transport = $this->createTransport();
        $transport->ack($envelope);
    }

    public function testRejectDeletesRowById(): void
    {
        $envelope = new Envelope(TestOutboxEvent::random(), [new TransportMessageIdStamp('55')]);

        $this->connection->expects($this->once())
            ->method('delete')
            ->with('messenger_outbox', [
                'id' => '55',
            ]);

        $transport = $this->createTransport();
        $transport->reject($envelope);
    }

    public function testKeepaliveUpdatesDeliveredAt(): void
    {
        $envelope = new Envelope(TestOutboxEvent::random(), [new TransportMessageIdStamp('33')]);

        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                'messenger_outbox',
                $this->callback(fn (array $data): bool => isset($data['delivered_at'])),
                [
                    'id' => '33',
                ],
                $this->anything(),
            );

        $transport = $this->createTransport();
        $transport->keepalive($envelope);
    }

    public function testKeepaliveDoesNothingWithoutStamp(): void
    {
        $envelope = new Envelope(TestOutboxEvent::random());

        $this->connection->expects($this->never())
            ->method('update');

        $transport = $this->createTransport();
        $transport->keepalive($envelope);
    }

    public function testGetRollsBackTransactionOnException(): void
    {
        $this->connection->expects($this->once())
            ->method('beginTransaction');
        $this->connection->expects($this->once())
            ->method('rollBack');
        $this->connection->expects($this->never())
            ->method('commit');

        $this->connection->method('executeQuery')
            ->willThrowException(new \RuntimeException('DB gone'));

        $transport = $this->createTransport();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB gone');

        iterator_to_array($transport->get());
    }

    public function testSendPreservesExistingStamps(): void
    {
        $partitionStamp = new PartitionKeyStamp('order-99');
        $existingStamp = new TransportMessageIdStamp('old-id');
        $envelope = new Envelope(TestOutboxEvent::random(), [$partitionStamp, $existingStamp]);

        $this->serializer->method('encode')
            ->willReturn([
                'body' => '{}',
                'headers' => [],
            ]);
        $this->connection->method('lastInsertId')
            ->willReturn('10');

        $transport = $this->createTransport();
        $result = $transport->send($envelope);

        $this->assertNotNull($result->last(PartitionKeyStamp::class), 'PartitionKeyStamp must be preserved');

        $transportStamps = $result->all(TransportMessageIdStamp::class);
        $this->assertCount(2, $transportStamps, 'Both old and new TransportMessageIdStamp must be present');
    }

    private function createTransport(): OrderedOutboxTransport
    {
        return new OrderedOutboxTransport(
            connection: $this->connection,
            serializer: $this->serializer,
            tableName: 'messenger_outbox',
            queueName: 'outbox',
        );
    }
}
