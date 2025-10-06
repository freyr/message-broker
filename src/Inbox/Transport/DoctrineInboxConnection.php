<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Inbox\Transport;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Types\Types;
use Freyr\Identity\Id;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;

/**
 * Doctrine Inbox Connection.
 *
 * Extends Symfony's DoctrineTransport Connection to use message_id as binary UUID v7 primary key.
 * Uses INSERT IGNORE for automatic deduplication based on message_id.
 */
class DoctrineInboxConnection extends Connection
{
    /**
     * Override send() to use message_id from headers as binary(16) primary key.
     *
     * @param array<string, mixed> $headers
     */
    public function send(string $body, array $headers, int $delay = 0): string
    {
        $now = new \DateTimeImmutable('UTC');
        $availableAt = $now->modify(\sprintf('%+d seconds', $delay / 1000));

        // Extract message_id from headers (required)
        if (!isset($headers['message_id'])) {
            throw new \RuntimeException('message_id header is required for inbox deduplication');
        }

        $messageIdValue = $headers['message_id'];
        if (!is_string($messageIdValue)) {
            throw new \RuntimeException('message_id header must be a string');
        }

        $messageId = Id::fromString($messageIdValue);

        /** @var string $tableName */
        $tableName = $this->configuration['table_name'];

        // Use INSERT IGNORE with message_id as primary key for deduplication
        $sql = sprintf(
            'INSERT IGNORE INTO %s (id, body, headers, queue_name, created_at, available_at) VALUES (?, ?, ?, ?, ?, ?)',
            $tableName
        );

        $this->driverConnection->beginTransaction();

        try {
            $result = $this->driverConnection->executeStatement($sql, [
                $messageId,
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

            // If INSERT IGNORE skipped (duplicate), return message_id
            if ($result === 0) {
                return 'duplicate-' . $messageId->__toString();
            }

            return $messageId->__toString();
        } catch (Exception $e) {
            $this->driverConnection->rollBack();
            throw $e;
        }
    }

    /**
     * Override configureSchema to use binary(16) for id column instead of bigint.
     */
    public function configureSchema(\Doctrine\DBAL\Schema\Schema $schema, \Doctrine\DBAL\Connection $forConnection, \Closure $isSameDatabase): void
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
