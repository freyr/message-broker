<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\Transport;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Freyr\Identity\Id;
use RuntimeException;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;

use function sprintf;

/**
 * Doctrine Outbox Connection.
 *
 * Extends Symfony's DoctrineTransport Connection to generate binary UUID v7 for primary key.
 */
class DoctrineOutboxConnection extends Connection
{
    /**
     * Override send() to use message_id from event body as binary UUID v7 primary key.
     *
     * @param array<string, mixed> $headers
     */
    public function send(string $body, array $headers, int $delay = 0): string
    {
        $now = new DateTimeImmutable('UTC');
        $availableAt = $now->modify(sprintf('%+d seconds', $delay / 1000));

        // Extract message_id from body (set by OutboxSerializer)
        $bodyData = json_decode($body, true);
        if (!isset($bodyData['message_id'])) {
            throw new RuntimeException('message_id is required in outbox event body');
        }

        $id = Id::fromString($bodyData['message_id']);

        /** @var string $tableName */
        $tableName = $this->configuration['table_name'];

        $sql = sprintf(
            'INSERT INTO %s (id, body, headers, queue_name, created_at, available_at) VALUES (?, ?, ?, ?, ?, ?)',
            $tableName
        );

        $this->driverConnection->beginTransaction();

        try {
            $this->driverConnection->executeStatement($sql, [
                $id,
                $body,
                json_encode($headers),
                $this->configuration['queue_name'],
                $now,
                $availableAt,
            ], [
                'id_binary',
                Types::STRING,
                Types::STRING,
                Types::STRING,
                Types::DATETIME_IMMUTABLE,
                Types::DATETIME_IMMUTABLE,
            ]);

            $this->driverConnection->commit();

            return $id->__toString();
        } catch (Exception $e) {
            $this->driverConnection->rollBack();
            throw $e;
        }
    }

    /**
     * Override configureSchema to use binary(16) for id column instead of bigint.
     */
    public function configureSchema(Schema $schema, \Doctrine\DBAL\Connection $forConnection, Closure $isSameDatabase): void
    {
        /** @var string $tableName */
        $tableName = $this->configuration['table_name'];

        if (!$schema->hasTable($tableName)) {
            $table = $schema->createTable($tableName);
            $table->addColumn('id', 'id_binary', ['notnull' => true]);
            $table->addColumn('body', Types::TEXT, ['notnull' => true]);
            $table->addColumn('headers', Types::TEXT, ['notnull' => true]);
            $table->addColumn('queue_name', Types::STRING, ['length' => 190, 'notnull' => true]);
            $table->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
            $table->addColumn('available_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
            $table->addColumn('delivered_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);

            $table->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()
                    ->setUnquotedColumnNames('id')
                    ->create()
            );
            $table->addIndex(['queue_name'], 'IDX_75EA56E0FB7336F0');
            $table->addIndex(['available_at'], 'IDX_75EA56E0E3BD61CE');
            $table->addIndex(['delivered_at'], 'IDX_75EA56E016BA31DB');
        }
    }
}
