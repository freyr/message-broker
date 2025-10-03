<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\Transport;

use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Factory for creating Doctrine Outbox Transport with binary UUID v7 support.
 */
final readonly class DoctrineOutboxTransportFactory implements TransportFactoryInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {


        // Remove Symfony-specific options (mimics parent DoctrineTransportFactory)
        unset($options['transport_name'], $options['use_notify']);

        // Build configuration from DSN
        $configuration = \Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection::buildConfiguration($dsn, $options);

        // Create custom outbox connection with binary UUID v7 support
        $connection = new DoctrineOutboxConnection($configuration, $this->connection);

        return new DoctrineTransport($connection, $serializer);
    }

    public function supports(string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'outbox://');
    }
}
