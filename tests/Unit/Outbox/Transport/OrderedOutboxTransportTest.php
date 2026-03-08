<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Outbox\Transport;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Freyr\MessageBroker\Contracts\PartitionKeyStamp;
use Freyr\MessageBroker\Outbox\Transport\OrderedOutboxTransport;
use Freyr\MessageBroker\Outbox\Transport\OutboxPlatformStrategy;
use Freyr\MessageBroker\Tests\Fixtures\TestOutboxEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

#[CoversClass(OrderedOutboxTransport::class)]
final class OrderedOutboxTransportTest extends TestCase
{
    private Connection&Stub $connection;
    private SerializerInterface&Stub $serializer;
    private OutboxPlatformStrategy&Stub $platformStrategy;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(Connection::class);
        $this->serializer = $this->createStub(SerializerInterface::class);
        $this->platformStrategy = $this->createStub(OutboxPlatformStrategy::class);
    }

    #[Test]
    public function itDelegatesToStrategyAndReturnsIdOnSend(): void
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

        $platformStrategy = $this->createMock(OutboxPlatformStrategy::class);
        $platformStrategy->expects($this->once())
            ->method('insertAndReturnId')
            ->with(
                $this->connection,
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
            )
            ->willReturn('42');

        $this->platformStrategy = $platformStrategy;
        $transport = $this->createTransport();
        $result = $transport->send($envelope);

        $stamp = $result->last(TransportMessageIdStamp::class);
        $this->assertNotNull($stamp);
        $this->assertSame('42', $stamp->getId());
    }

    #[Test]
    public function itDefaultsToEmptyPartitionKeyWhenStampMissingOnSend(): void
    {
        $envelope = new Envelope(TestOutboxEvent::random());

        $this->serializer->method('encode')
            ->willReturn([
                'body' => '{}',
                'headers' => [],
            ]);

        $platformStrategy = $this->createMock(OutboxPlatformStrategy::class);
        $platformStrategy->expects($this->once())
            ->method('insertAndReturnId')
            ->with(
                $this->connection,
                'messenger_outbox',
                $this->callback(fn (array $data): bool => $data['partition_key'] === ''),
                $this->anything(),
            )
            ->willReturn('1');

        $this->platformStrategy = $platformStrategy;
        $transport = $this->createTransport();
        $transport->send($envelope);
    }

    #[Test]
    public function itReturnsEnvelopeWhenMessageAvailableOnGet(): void
    {
        $event = TestOutboxEvent::random();
        $envelope = new Envelope($event);

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('beginTransaction');
        $connection->expects($this->once())
            ->method('commit');

        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')
            ->willReturn([
                'id' => '7',
                'body' => '{"payload":"Hello"}',
                'headers' => '{"type":"test.event.sent"}',
            ]);

        $connection->method('executeQuery')
            ->willReturn($result);
        $connection->expects($this->once())
            ->method('update')
            ->with('messenger_outbox', $this->anything(), [
                'id' => '7',
            ], $this->anything());

        $this->serializer->method('decode')
            ->willReturn($envelope);

        $this->connection = $connection;
        $transport = $this->createTransport();
        $envelopes = iterator_to_array($transport->get());

        $this->assertCount(1, $envelopes);
        $stamp = $envelopes[0]->last(TransportMessageIdStamp::class);
        $this->assertNotNull($stamp);
        $this->assertSame('7', $stamp->getId());
    }

    #[Test]
    public function itReturnsEmptyWhenNoMessagesAvailableOnGet(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('beginTransaction');
        $connection->expects($this->once())
            ->method('commit');

        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')
            ->willReturn(false);

        $connection->method('executeQuery')
            ->willReturn($result);

        $this->connection = $connection;
        $transport = $this->createTransport();
        $envelopes = iterator_to_array($transport->get());

        $this->assertCount(0, $envelopes);
    }

    #[Test]
    public function itDeletesRowByIdOnAck(): void
    {
        $envelope = new Envelope(TestOutboxEvent::random(), [new TransportMessageIdStamp('99')]);

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('delete')
            ->with('messenger_outbox', [
                'id' => '99',
            ]);

        $this->connection = $connection;
        $transport = $this->createTransport();
        $transport->ack($envelope);
    }

    #[Test]
    public function itDeletesRowByIdOnReject(): void
    {
        $envelope = new Envelope(TestOutboxEvent::random(), [new TransportMessageIdStamp('55')]);

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('delete')
            ->with('messenger_outbox', [
                'id' => '55',
            ]);

        $this->connection = $connection;
        $transport = $this->createTransport();
        $transport->reject($envelope);
    }

    #[Test]
    public function itUpdatesDeliveredAtOnKeepalive(): void
    {
        $envelope = new Envelope(TestOutboxEvent::random(), [new TransportMessageIdStamp('33')]);

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('update')
            ->with(
                'messenger_outbox',
                $this->callback(fn (array $data): bool => isset($data['delivered_at'])),
                [
                    'id' => '33',
                ],
                $this->anything(),
            );

        $this->connection = $connection;
        $transport = $this->createTransport();
        $transport->keepalive($envelope);
    }

    #[Test]
    public function itDoesNothingOnKeepaliveWithoutStamp(): void
    {
        $envelope = new Envelope(TestOutboxEvent::random());

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())
            ->method('update');

        $this->connection = $connection;
        $transport = $this->createTransport();
        $transport->keepalive($envelope);
    }

    #[Test]
    public function itRollsBackTransactionOnExceptionDuringGet(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('beginTransaction');
        $connection->expects($this->once())
            ->method('rollBack');
        $connection->expects($this->never())
            ->method('commit');

        $connection->method('executeQuery')
            ->willThrowException(new \RuntimeException('DB gone'));

        $this->connection = $connection;
        $transport = $this->createTransport();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB gone');

        iterator_to_array($transport->get());
    }

    #[Test]
    public function itPreservesExistingStampsOnSend(): void
    {
        $partitionStamp = new PartitionKeyStamp('order-99');
        $existingStamp = new TransportMessageIdStamp('old-id');
        $envelope = new Envelope(TestOutboxEvent::random(), [$partitionStamp, $existingStamp]);

        $this->serializer->method('encode')
            ->willReturn([
                'body' => '{}',
                'headers' => [],
            ]);

        $strategy = $this->createStub(OutboxPlatformStrategy::class);
        $strategy->method('insertAndReturnId')
            ->willReturn('10');

        $transport = new OrderedOutboxTransport(
            connection: $this->createStub(Connection::class),
            serializer: $this->serializer,
            platformStrategy: $strategy,
            tableName: 'messenger_outbox',
            queueName: 'outbox',
        );
        $result = $transport->send($envelope);

        $this->assertNotNull($result->last(PartitionKeyStamp::class), 'PartitionKeyStamp must be preserved');

        $transportStamps = $result->all(TransportMessageIdStamp::class);
        $this->assertCount(2, $transportStamps, 'Both old and new TransportMessageIdStamp must be present');
    }

    #[Test]
    public function itRejectsInvalidTableNameInConstructor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Table name must contain only alphanumeric characters and underscores');

        new OrderedOutboxTransport(
            connection: $this->connection,
            serializer: $this->serializer,
            platformStrategy: $this->platformStrategy,
            tableName: 'DROP TABLE users; --',
            queueName: 'outbox',
        );
    }

    #[Test]
    public function itIncludesPlatformFilterInQueryOnGet(): void
    {
        $this->platformStrategy->method('buildHeadOfLineFilter')
            ->willReturn(' AND sub.custom_filter = true');

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('beginTransaction');

        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')
            ->willReturn(false);

        $connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->callback(function (string $sql): bool {
                    $this->assertStringContainsString('AND sub.custom_filter = true', $sql);

                    return true;
                }),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($result);

        $this->connection = $connection;
        $transport = $this->createTransport();
        iterator_to_array($transport->get());
    }

    private function createTransport(): OrderedOutboxTransport
    {
        return new OrderedOutboxTransport(
            connection: $this->connection,
            serializer: $this->serializer,
            platformStrategy: $this->platformStrategy,
            tableName: 'messenger_outbox',
            queueName: 'outbox',
        );
    }
}
