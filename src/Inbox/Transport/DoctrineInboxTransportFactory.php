<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Inbox\Transport;

use Doctrine\DBAL\Connection as DBALConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Doctrine Inbox Transport Factory.
 *
 * Creates DoctrineInboxTransport instances with INSERT IGNORE support.
 *
 * @implements TransportFactoryInterface<DoctrineTransport>
 */
final readonly class DoctrineInboxTransportFactory implements TransportFactoryInterface
{
    public function __construct(
        private DBALConnection $connection,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        // Remove Symfony-specific options that are not recognized by Doctrine Connection
        $filteredOptions = array_filter(
            $options,
            static fn (string $key): bool => !in_array($key, ['transport_name'], true),
            ARRAY_FILTER_USE_KEY
        );

        // Parse DSN: inbox://default?queue_name=inbox
        $configuration = Connection::buildConfiguration($dsn, $filteredOptions);

        // Create custom connection with INSERT IGNORE support
        $connection = new DoctrineInboxConnection($configuration, $this->connection);

        return new DoctrineTransport(
            $connection,
            $serializer
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'inbox://');
    }
}
