<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Outbox\Transport;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ConnectionRegistry;
use Freyr\MessageBroker\Outbox\Transport\OrderedOutboxTransport;
use Freyr\MessageBroker\Outbox\Transport\OrderedOutboxTransportFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

#[CoversClass(OrderedOutboxTransportFactory::class)]
final class OrderedOutboxTransportFactoryTest extends TestCase
{
    public function testSupportsOrderedDoctrineDsn(): void
    {
        $factory = new OrderedOutboxTransportFactory($this->createStub(ConnectionRegistry::class));

        $this->assertTrue($factory->supports('ordered-doctrine://default', []));
    }

    public function testDoesNotSupportStandardDoctrineDsn(): void
    {
        $factory = new OrderedOutboxTransportFactory($this->createStub(ConnectionRegistry::class));

        $this->assertFalse($factory->supports('doctrine://default', []));
    }

    public function testDoesNotSupportAmqpDsn(): void
    {
        $factory = new OrderedOutboxTransportFactory($this->createStub(ConnectionRegistry::class));

        $this->assertFalse($factory->supports('amqp://guest:guest@localhost', []));
    }

    public function testCreateTransportReturnsOrderedOutboxTransport(): void
    {
        $registry = $this->createStub(ConnectionRegistry::class);
        $registry->method('getConnection')
            ->with('default')
            ->willReturn($this->createStub(Connection::class));

        $factory = new OrderedOutboxTransportFactory($registry);
        $serializer = $this->createStub(SerializerInterface::class);

        $transport = $factory->createTransport(
            'ordered-doctrine://default?table_name=messenger_outbox&queue_name=outbox',
            [],
            $serializer,
        );

        $this->assertInstanceOf(OrderedOutboxTransport::class, $transport);
    }

    public function testCreateTransportWithCustomOptions(): void
    {
        $registry = $this->createStub(ConnectionRegistry::class);
        $registry->method('getConnection')
            ->with('events')
            ->willReturn($this->createStub(Connection::class));

        $factory = new OrderedOutboxTransportFactory($registry);
        $serializer = $this->createStub(SerializerInterface::class);

        $transport = $factory->createTransport(
            'ordered-doctrine://events?table_name=my_outbox&queue_name=my_queue&redeliver_timeout=7200',
            [],
            $serializer,
        );

        $this->assertInstanceOf(OrderedOutboxTransport::class, $transport);
    }

    public function testCreateTransportWithAutoSetup(): void
    {
        $registry = $this->createStub(ConnectionRegistry::class);
        $registry->method('getConnection')
            ->willReturn($this->createStub(Connection::class));

        $factory = new OrderedOutboxTransportFactory($registry);
        $serializer = $this->createStub(SerializerInterface::class);

        $transport = $factory->createTransport('ordered-doctrine://default?auto_setup=true', [], $serializer);

        $this->assertInstanceOf(OrderedOutboxTransport::class, $transport);
    }

    public function testCreateTransportThrowsOnInvalidDsn(): void
    {
        $factory = new OrderedOutboxTransportFactory($this->createStub(ConnectionRegistry::class));

        $this->expectException(\InvalidArgumentException::class);

        $factory->createTransport('://invalid', [], $this->createStub(SerializerInterface::class));
    }

    public function testCreateTransportRejectsInvalidTableName(): void
    {
        $registry = $this->createStub(ConnectionRegistry::class);
        $registry->method('getConnection')
            ->willReturn($this->createStub(Connection::class));

        $factory = new OrderedOutboxTransportFactory($registry);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Table name must contain only alphanumeric characters');

        $factory->createTransport(
            'ordered-doctrine://default?table_name=drop%20table%20users',
            [],
            $this->createStub(SerializerInterface::class),
        );
    }

    public function testCreateTransportRejectsTableNameStartingWithDigit(): void
    {
        $registry = $this->createStub(ConnectionRegistry::class);
        $registry->method('getConnection')
            ->willReturn($this->createStub(Connection::class));

        $factory = new OrderedOutboxTransportFactory($registry);

        $this->expectException(\InvalidArgumentException::class);

        $factory->createTransport(
            'ordered-doctrine://default?table_name=1invalid',
            [],
            $this->createStub(SerializerInterface::class),
        );
    }

    public function testCreateTransportWrapsConnectionNotFoundInTransportException(): void
    {
        $registry = $this->createStub(ConnectionRegistry::class);
        $registry->method('getConnection')
            ->willThrowException(new \InvalidArgumentException('No connection named "missing"'));

        $factory = new OrderedOutboxTransportFactory($registry);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Could not find Doctrine connection "missing"');

        $factory->createTransport(
            'ordered-doctrine://missing',
            [],
            $this->createStub(SerializerInterface::class),
        );
    }

    public function testDsnQueryOverridesOptionsWhichOverrideDefaults(): void
    {
        $registry = $this->createStub(ConnectionRegistry::class);
        $registry->method('getConnection')
            ->willReturn($this->createStub(Connection::class));

        $factory = new OrderedOutboxTransportFactory($registry);

        // DSN has table_name=from_dsn, options have table_name=from_options
        // DSN should win
        $transport = $factory->createTransport(
            'ordered-doctrine://default?table_name=from_dsn',
            [
                'table_name' => 'from_options',
            ],
            $this->createStub(SerializerInterface::class),
        );

        $this->assertInstanceOf(OrderedOutboxTransport::class, $transport);
    }

    public function testCreateTransportWithNoQueryStringUsesDefaults(): void
    {
        $registry = $this->createStub(ConnectionRegistry::class);
        $registry->method('getConnection')
            ->willReturn($this->createStub(Connection::class));

        $factory = new OrderedOutboxTransportFactory($registry);

        $transport = $factory->createTransport(
            'ordered-doctrine://default',
            [],
            $this->createStub(SerializerInterface::class),
        );

        $this->assertInstanceOf(OrderedOutboxTransport::class, $transport);
    }
}
