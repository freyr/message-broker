<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\Transport;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Freyr\MessageBroker\Outbox\PartitionKeyStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

if (interface_exists(\Symfony\Component\Messenger\Transport\Receiver\KeepaliveReceiverInterface::class)) {
    /** @internal Compatibility shim — Symfony >=7.1 adds KeepaliveReceiverInterface. */
    interface OrderedOutboxTransportKeepaliveCompat extends TransportInterface, SetupableTransportInterface, \Symfony\Component\Messenger\Transport\Receiver\KeepaliveReceiverInterface {}
} else {
    /** @internal Compatibility shim — Symfony <7.1 where KeepaliveReceiverInterface does not exist. */
    interface OrderedOutboxTransportKeepaliveCompat extends TransportInterface, SetupableTransportInterface {}
}

/**
 * Ordered outbox transport with per-partition FIFO delivery.
 *
 * Uses a head-of-line query to ensure only the oldest message per partition key
 * can be claimed by a worker. This guarantees per-aggregate causal ordering
 * while maintaining full parallelism across different partitions.
 *
 * Activate by using the `ordered-doctrine://` DSN scheme.
 */
final class OrderedOutboxTransport implements OrderedOutboxTransportKeepaliveCompat
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SerializerInterface $serializer,
        private readonly string $tableName,
        private readonly string $queueName,
        private readonly int $redeliverTimeout = 3600,
        private bool $autoSetup = false,
    ) {}

    public function send(Envelope $envelope): Envelope
    {
        if ($this->autoSetup) {
            $this->setup();
            $this->autoSetup = false;
        }

        $stamp = $envelope->last(PartitionKeyStamp::class);
        $partitionKey = $stamp instanceof PartitionKeyStamp ? $stamp->partitionKey : '';
        $encoded = $this->serializer->encode($envelope);
        $now = new \DateTimeImmutable();

        $this->connection->insert($this->tableName, [
            'body' => $encoded['body'],
            'headers' => json_encode($encoded['headers'] ?? [], JSON_THROW_ON_ERROR),
            'queue_name' => $this->queueName,
            'created_at' => $now,
            'available_at' => $now,
            'partition_key' => $partitionKey,
        ], [
            'created_at' => Types::DATETIME_IMMUTABLE,
            'available_at' => Types::DATETIME_IMMUTABLE,
        ]);

        $id = $this->connection->lastInsertId();

        return $envelope->with(new TransportMessageIdStamp((string) $id));
    }

    public function get(): iterable
    {
        if ($this->autoSetup) {
            $this->setup();
            $this->autoSetup = false;
        }

        $this->connection->beginTransaction();

        try {
            $now = new \DateTimeImmutable();
            $redeliverLimit = $now->modify(sprintf('-%d seconds', $this->redeliverTimeout));

            $sql = sprintf(
                'SELECT m.* FROM %s m '
                .'WHERE m.id IN ('
                .'  SELECT MIN(sub.id) FROM %s sub'
                .'  WHERE sub.queue_name = ?'
                .'    AND (sub.delivered_at IS NULL OR sub.delivered_at < ?)'
                .'    AND sub.available_at <= ?'
                .'  GROUP BY sub.partition_key'
                .') LIMIT 1 FOR UPDATE SKIP LOCKED',
                $this->tableName,
                $this->tableName,
            );

            $result = $this->connection->executeQuery($sql, [
                $this->queueName,
                $redeliverLimit,
                $now,
            ], [Types::STRING, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE]);

            $row = $result->fetchAssociative();

            if ($row === false) {
                $this->connection->commit();

                return [];
            }

            $this->connection->update(
                $this->tableName,
                [
                    'delivered_at' => $now,
                ],
                [
                    'id' => $row['id'],
                ],
                [
                    'delivered_at' => Types::DATETIME_IMMUTABLE,
                ],
            );

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }

        $body = \is_string($row['body']) ? $row['body'] : '';
        $headersJson = \is_string($row['headers']) ? $row['headers'] : '{}';
        /** @var array<string, string> $headers */
        $headers = json_decode($headersJson, true, 512, JSON_THROW_ON_ERROR);

        $envelope = $this->serializer->decode([
            'body' => $body,
            'headers' => $headers,
        ]);

        $id = isset($row['id']) && (\is_string($row['id']) || \is_int($row['id']))
            ? (string) $row['id']
            : '0';

        yield $envelope->with(new TransportMessageIdStamp($id));
    }

    public function ack(Envelope $envelope): void
    {
        $stamp = $envelope->last(TransportMessageIdStamp::class);

        if ($stamp === null) {
            return;
        }

        $this->connection->delete($this->tableName, [
            'id' => $stamp->getId(),
        ]);
    }

    public function reject(Envelope $envelope): void
    {
        $this->ack($envelope);
    }

    public function keepalive(Envelope $envelope, ?int $seconds = null): void
    {
        $stamp = $envelope->last(TransportMessageIdStamp::class);

        if ($stamp === null) {
            return;
        }

        $this->connection->update(
            $this->tableName,
            [
                'delivered_at' => new \DateTimeImmutable(),
            ],
            [
                'id' => $stamp->getId(),
            ],
            [
                'delivered_at' => Types::DATETIME_IMMUTABLE,
            ],
        );
    }

    public function setup(): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        if ($schemaManager->tablesExist([$this->tableName])) {
            $this->addPartitionKeyColumnIfMissing($schemaManager);

            return;
        }

        $table = new Table($this->tableName);

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'unsigned' => true,
        ]);
        $table->addColumn('body', Types::TEXT);
        $table->addColumn('headers', Types::TEXT);
        $table->addColumn('queue_name', Types::STRING, [
            'length' => 190,
        ]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('available_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('delivered_at', Types::DATETIME_IMMUTABLE, [
            'notnull' => false,
        ]);
        $table->addColumn('partition_key', Types::STRING, [
            'length' => 255,
            'default' => '',
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['queue_name', 'available_at', 'delivered_at', 'id'], 'idx_outbox_available');
        $table->addIndex(
            ['queue_name', 'partition_key', 'available_at', 'delivered_at', 'id'],
            'idx_outbox_partition_order'
        );

        $schemaManager->createTable($table);
    }

    /**
     * @param \Doctrine\DBAL\Schema\AbstractSchemaManager<\Doctrine\DBAL\Platforms\AbstractPlatform> $schemaManager
     */
    private function addPartitionKeyColumnIfMissing(\Doctrine\DBAL\Schema\AbstractSchemaManager $schemaManager): void
    {
        $columns = $schemaManager->listTableColumns($this->tableName);

        if (isset($columns['partition_key'])) {
            return;
        }

        $this->connection->executeStatement(sprintf(
            'ALTER TABLE %s ADD COLUMN partition_key VARCHAR(255) NOT NULL DEFAULT \'\'',
            $this->tableName,
        ));

        $this->connection->executeStatement(sprintf(
            'CREATE INDEX idx_outbox_partition_order ON %s (queue_name, partition_key, available_at, delivered_at, id)',
            $this->tableName,
        ));
    }
}
