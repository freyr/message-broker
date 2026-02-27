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

    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
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

        return new OrderedOutboxTransport(
            connection: $driverConnection, // @phpstan-ignore argument.type (ConnectionRegistry returns object, we need DBAL Connection)
            serializer: $serializer,
            tableName: $configuration['table_name'],
            queueName: $configuration['queue_name'],
            redeliverTimeout: (int) $configuration['redeliver_timeout'],
            autoSetup: $configuration['auto_setup'],
        );
    }

    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'ordered-doctrine://');
    }
}
