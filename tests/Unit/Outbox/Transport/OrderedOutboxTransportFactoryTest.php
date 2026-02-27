<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Outbox\Transport;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ConnectionRegistry;
use Freyr\MessageBroker\Outbox\Transport\OrderedOutboxTransport;
use Freyr\MessageBroker\Outbox\Transport\OrderedOutboxTransportFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

#[CoversClass(OrderedOutboxTransportFactory::class)]
final class OrderedOutboxTransportFactoryTest extends TestCase
{
    public function testSupportsOrderedDoctrineDsn(): void
    {
        $factory = new OrderedOutboxTransportFactory(
            $this->createMock(ConnectionRegistry::class),
        );

        $this->assertTrue($factory->supports('ordered-doctrine://default', []));
    }

    public function testDoesNotSupportStandardDoctrineDsn(): void
    {
        $factory = new OrderedOutboxTransportFactory(
            $this->createMock(ConnectionRegistry::class),
        );

        $this->assertFalse($factory->supports('doctrine://default', []));
    }

    public function testDoesNotSupportAmqpDsn(): void
    {
        $factory = new OrderedOutboxTransportFactory(
            $this->createMock(ConnectionRegistry::class),
        );

        $this->assertFalse($factory->supports('amqp://guest:guest@localhost', []));
    }

    public function testCreateTransportReturnsOrderedOutboxTransport(): void
    {
        $registry = $this->createMock(ConnectionRegistry::class);
        $registry->method('getConnection')
            ->with('default')
            ->willReturn($this->createMock(Connection::class));

        $factory = new OrderedOutboxTransportFactory($registry);
        $serializer = $this->createMock(SerializerInterface::class);

        $transport = $factory->createTransport(
            'ordered-doctrine://default?table_name=messenger_outbox&queue_name=outbox',
            [],
            $serializer,
        );

        $this->assertInstanceOf(OrderedOutboxTransport::class, $transport);
    }

    public function testCreateTransportWithCustomOptions(): void
    {
        $registry = $this->createMock(ConnectionRegistry::class);
        $registry->method('getConnection')
            ->with('events')
            ->willReturn($this->createMock(Connection::class));

        $factory = new OrderedOutboxTransportFactory($registry);
        $serializer = $this->createMock(SerializerInterface::class);

        $transport = $factory->createTransport(
            'ordered-doctrine://events?table_name=my_outbox&queue_name=my_queue&redeliver_timeout=7200',
            [],
            $serializer,
        );

        $this->assertInstanceOf(OrderedOutboxTransport::class, $transport);
    }

    public function testCreateTransportWithAutoSetup(): void
    {
        $registry = $this->createMock(ConnectionRegistry::class);
        $registry->method('getConnection')
            ->willReturn($this->createMock(Connection::class));

        $factory = new OrderedOutboxTransportFactory($registry);
        $serializer = $this->createMock(SerializerInterface::class);

        $transport = $factory->createTransport(
            'ordered-doctrine://default?auto_setup=true',
            [],
            $serializer,
        );

        $this->assertInstanceOf(OrderedOutboxTransport::class, $transport);
    }

    public function testCreateTransportThrowsOnInvalidDsn(): void
    {
        $factory = new OrderedOutboxTransportFactory(
            $this->createMock(ConnectionRegistry::class),
        );

        $this->expectException(\InvalidArgumentException::class);

        $factory->createTransport(
            '://invalid',
            [],
            $this->createMock(SerializerInterface::class),
        );
    }
}
