<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\Transport;

use Doctrine\Persistence\ConnectionRegistry;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Creates an OrderedOutboxTransport from an `ordered-doctrine://` DSN.
 *
 * DSN format: ordered-doctrine://{connection}?table_name={table}&queue_name={queue}&redeliver_timeout={seconds}&auto_setup={bool}
 *
 * @implements TransportFactoryInterface<OrderedOutboxTransport>
 */
final readonly class OrderedOutboxTransportFactory implements TransportFactoryInterface
{
    private const DEFAULT_OPTIONS = [
        'table_name' => 'messenger_messages',
        'queue_name' => 'default',
        'redeliver_timeout' => 3600,
        'auto_setup' => true,
    ];

    public function __construct(
        private ConnectionRegistry $registry,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function createTransport(
        #[\SensitiveParameter] string $dsn,
        array $options,
        SerializerInterface $serializer,
    ): TransportInterface {
        $params = parse_url($dsn);

        if ($params === false || !isset($params['host'])) {
            throw new \InvalidArgumentException('The given ordered-doctrine:// DSN is invalid.');
        }

        $query = [];
        if (isset($params['query'])) {
            parse_str($params['query'], $query);
        }

        $configuration = $query + $options + self::DEFAULT_OPTIONS;
        $configuration['auto_setup'] = filter_var($configuration['auto_setup'], \FILTER_VALIDATE_BOOL);

        $connectionName = $params['host'];

        try {
            $driverConnection = $this->registry->getConnection($connectionName);
        } catch (\InvalidArgumentException $e) {
            throw new TransportException(sprintf(
                'Could not find Doctrine connection "%s" from ordered-doctrine:// DSN.',
                $connectionName,
            ), 0, $e);
        }

        $tableName = \is_string($configuration['table_name']) ? $configuration['table_name'] : 'messenger_messages';
        $queueName = \is_string($configuration['queue_name']) ? $configuration['queue_name'] : 'default';
        $rawTimeout = $configuration['redeliver_timeout'];
        $redeliverTimeout = \is_int($rawTimeout) ? $rawTimeout : (\is_numeric($rawTimeout) ? (int) $rawTimeout : 3600);

        /** @var \Doctrine\DBAL\Connection $driverConnection */
        return new OrderedOutboxTransport(
            connection: $driverConnection,
            serializer: $serializer,
            tableName: $tableName,
            queueName: $queueName,
            redeliverTimeout: $redeliverTimeout,
            autoSetup: (bool) $configuration['auto_setup'],
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'ordered-doctrine://');
    }
}
