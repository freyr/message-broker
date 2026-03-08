<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Outbox\Transport;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
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
        $factory = new OrderedOutboxTransportFactory($this->registryWith('default', $this->mysqlConnection()));

        $transport = $factory->createTransport(
            'ordered-doctrine://default?table_name=messenger_outbox&queue_name=outbox',
            [],
            $this->createStub(SerializerInterface::class),
        );

        $this->assertInstanceOf(OrderedOutboxTransport::class, $transport);
    }

    public function testCreateTransportWithCustomOptions(): void
    {
        $factory = new OrderedOutboxTransportFactory($this->registryWith('events', $this->mysqlConnection()));

        $transport = $factory->createTransport(
            'ordered-doctrine://events?table_name=my_outbox&queue_name=my_queue&redeliver_timeout=7200',
            [],
            $this->createStub(SerializerInterface::class),
        );

        $this->assertInstanceOf(OrderedOutboxTransport::class, $transport);
    }

    public function testCreateTransportWithAutoSetup(): void
    {
        $factory = new OrderedOutboxTransportFactory($this->registryWith('default', $this->mysqlConnection()));

        $transport = $factory->createTransport(
            'ordered-doctrine://default?auto_setup=true',
            [],
            $this->createStub(SerializerInterface::class)
        );

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
        $factory = new OrderedOutboxTransportFactory($this->registryWith('default', $this->mysqlConnection()));

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
        $factory = new OrderedOutboxTransportFactory($this->registryWith('default', $this->mysqlConnection()));

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

    public function testCreateTransportWithNoQueryStringUsesDefaults(): void
    {
        $factory = new OrderedOutboxTransportFactory($this->registryWith('default', $this->mysqlConnection()));

        $transport = $factory->createTransport(
            'ordered-doctrine://default',
            [],
            $this->createStub(SerializerInterface::class),
        );

        $this->assertInstanceOf(OrderedOutboxTransport::class, $transport);
    }

    public function testItInjectsMySqlStrategyForMySqlPlatform(): void
    {
        $factory = new OrderedOutboxTransportFactory($this->registryWith('default', $this->mysqlConnection()));

        $transport = $factory->createTransport(
            'ordered-doctrine://default',
            [],
            $this->createStub(SerializerInterface::class),
        );

        $this->assertInstanceOf(OrderedOutboxTransport::class, $transport);
    }

    public function testItInjectsMySqlStrategyForMariaDbPlatform(): void
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

    public function testItInjectsPostgreSqlStrategyForPostgreSqlPlatform(): void
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

    public function testItThrowsForUnsupportedPlatform(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')
            ->willReturn(new SQLitePlatform());

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
