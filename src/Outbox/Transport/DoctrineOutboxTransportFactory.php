<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\Transport;

use Doctrine\DBAL\Connection;
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
        // Remove Symfony-specific options that are not recognized by Doctrine Connection
        $filteredOptions = array_filter(
            $options,
            static fn (string $key): bool => !in_array($key, ['transport_name'], true),
            ARRAY_FILTER_USE_KEY
        );

        // Parse DSN: outbox://default?queue_name=outbox
        $configuration = \Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection::buildConfiguration($dsn, $filteredOptions);

        $connection = new DoctrineOutboxConnection(
            $configuration,
            $this->connection
        );

        return new \Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport(
            $connection,
            $serializer
        );
    }

    public function supports(string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'outbox://');
    }
}
