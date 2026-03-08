<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Outbox\Transport;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Persistence\ConnectionRegistry;
use Freyr\MessageBroker\Outbox\Transport\OrderedOutboxTransport;
use Freyr\MessageBroker\Outbox\Transport\OrderedOutboxTransportFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

#[CoversClass(OrderedOutboxTransportFactory::class)]
final class OrderedOutboxTransportFactoryTest extends TestCase
{
    #[Test]
    public function itSupportsOrderedDoctrineDsn(): void
    {
        $factory = new OrderedOutboxTransportFactory($this->createStub(ConnectionRegistry::class));

        $this->assertTrue($factory->supports('ordered-doctrine://default', []));
    }

    #[Test]
    public function itDoesNotSupportStandardDoctrineDsn(): void
    {
        $factory = new OrderedOutboxTransportFactory($this->createStub(ConnectionRegistry::class));

        $this->assertFalse($factory->supports('doctrine://default', []));
    }

    #[Test]
    public function itDoesNotSupportAmqpDsn(): void
    {
        $factory = new OrderedOutboxTransportFactory($this->createStub(ConnectionRegistry::class));

        $this->assertFalse($factory->supports('amqp://guest:guest@localhost', []));
    }

    #[Test]
    public function itCreatesTransportAsOrderedOutboxTransport(): void
    {
        $factory = new OrderedOutboxTransportFactory($this->registryWith('default', $this->mysqlConnection()));

        $transport = $factory->createTransport(
            'ordered-doctrine://default?table_name=messenger_outbox&queue_name=outbox',
            [],
            $this->createStub(SerializerInterface::class),
        );

        $this->assertInstanceOf(OrderedOutboxTransport::class, $transport);
    }

    #[Test]
    public function itCreatesTransportWithCustomOptions(): void
    {
        $factory = new OrderedOutboxTransportFactory($this->registryWith('events', $this->mysqlConnection()));

        $transport = $factory->createTransport(
            'ordered-doctrine://events?table_name=my_outbox&queue_name=my_queue&redeliver_timeout=7200',
            [],
            $this->createStub(SerializerInterface::class),
        );

        $this->assertInstanceOf(OrderedOutboxTransport::class, $transport);
    }

    #[Test]
    public function itCreatesTransportWithAutoSetup(): void
    {
        $factory = new OrderedOutboxTransportFactory($this->registryWith('default', $this->mysqlConnection()));

        $transport = $factory->createTransport(
            'ordered-doctrine://default?auto_setup=true',
            [],
            $this->createStub(SerializerInterface::class)
        );

        $this->assertInstanceOf(OrderedOutboxTransport::class, $transport);
    }

    #[Test]
    public function itThrowsOnInvalidDsnWhenCreatingTransport(): void
    {
        $factory = new OrderedOutboxTransportFactory($this->createStub(ConnectionRegistry::class));

        $this->expectException(\InvalidArgumentException::class);

        $factory->createTransport('://invalid', [], $this->createStub(SerializerInterface::class));
    }

    #[Test]
    public function itRejectsInvalidTableNameWhenCreatingTransport(): void
    {
        $factory = new OrderedOutboxTransportFactory($this->registryWith('default', $this->mysqlConnection()));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Table name must contain only alphanumeric characters');

        $factory->createTransport(
            'ordered-doctrine://default?table_name=drop%20table%20users',
            [],
            $this->createStub(SerializerInterface::class),
        );
    }

    #[Test]
    public function itRejectsTableNameStartingWithDigitWhenCreatingTransport(): void
    {
        $factory = new OrderedOutboxTransportFactory($this->registryWith('default', $this->mysqlConnection()));

        $this->expectException(\InvalidArgumentException::class);

        $factory->createTransport(
            'ordered-doctrine://default?table_name=1invalid',
            [],
            $this->createStub(SerializerInterface::class),
        );
    }

    #[Test]
    public function itWrapsConnectionNotFoundInTransportException(): void
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

    #[Test]
    public function itOverridesDefaultsWithDsnQuery(): void
    {
        $factory = new OrderedOutboxTransportFactory($this->registryWith('default', $this->mysqlConnection()));

        $transport = $factory->createTransport(
            'ordered-doctrine://default?table_name=from_dsn',
            [
                'table_name' => 'from_options',
            ],
            $this->createStub(SerializerInterface::class),
        );

        $this->assertInstanceOf(OrderedOutboxTransport::class, $transport);
    }

    #[Test]
    public function itUsesDefaultsWhenNoQueryString(): void
    {
        $factory = new OrderedOutboxTransportFactory($this->registryWith('default', $this->mysqlConnection()));

        $transport = $factory->createTransport(
            'ordered-doctrine://default',
            [],
            $this->createStub(SerializerInterface::class),
        );

        $this->assertInstanceOf(OrderedOutboxTransport::class, $transport);
    }

    #[Test]
    public function itInjectsMySqlStrategyForMySqlPlatform(): void
    {
        $factory = new OrderedOutboxTransportFactory($this->registryWith('default', $this->mysqlConnection()));

        $transport = $factory->createTransport(
            'ordered-doctrine://default',
            [],
            $this->createStub(SerializerInterface::class),
        );

        $this->assertInstanceOf(OrderedOutboxTransport::class, $transport);
    }

    #[Test]
    public function itInjectsMySqlStrategyForMariaDbPlatform(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')
            ->willReturn(new MariaDBPlatform());

        $factory = new OrderedOutboxTransportFactory($this->registryWith('default', $connection));

        $transport = $factory->createTransport(
            'ordered-doctrine://default',
            [],
            $this->createStub(SerializerInterface::class),
        );

        $this->assertInstanceOf(OrderedOutboxTransport::class, $transport);
    }

    #[Test]
    public function itInjectsPostgreSqlStrategyForPostgreSqlPlatform(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')
            ->willReturn(new PostgreSQLPlatform());

        $factory = new OrderedOutboxTransportFactory($this->registryWith('default', $connection));

        $transport = $factory->createTransport(
            'ordered-doctrine://default',
            [],
            $this->createStub(SerializerInterface::class),
        );

        $this->assertInstanceOf(OrderedOutboxTransport::class, $transport);
    }

    #[Test]
    public function itThrowsForUnsupportedPlatform(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')
            ->willReturn($this->createStub(AbstractPlatform::class));

        $factory = new OrderedOutboxTransportFactory($this->registryWith('default', $connection));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Unsupported database platform');

        $factory->createTransport(
            'ordered-doctrine://default',
            [],
            $this->createStub(SerializerInterface::class),
        );
    }

    private function mysqlConnection(): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')
            ->willReturn(new MySQLPlatform());

        return $connection;
    }

    private function registryWith(string $name, Connection $connection): ConnectionRegistry
    {
        $registry = $this->createStub(ConnectionRegistry::class);
        $registry->method('getConnection')
            ->with($name)
            ->willReturn($connection);

        return $registry;
    }
}
