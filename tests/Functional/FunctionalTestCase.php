<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\MessageBroker\Serializer\Format;
use Freyr\MessageBroker\Storage\MySqlPlatform;
use Freyr\MessageBroker\Storage\Platform;
use Freyr\MessageBroker\Storage\PostgreSqlPlatform;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

abstract class FunctionalTestCase extends TestCase
{
    protected static PDO $pdo;

    /** Subclasses producing Avro bodies override this. */
    protected static function outboxFormat(): Format
    {
        return Format::Json;
    }

    protected static function dbEngine(): string
    {
        return getenv('DB_ENGINE') ?: 'mysql';
    }

    protected static function isPostgres(): bool
    {
        return self::dbEngine() === 'pgsql';
    }

    protected static function platform(): Platform
    {
        return self::isPostgres() ? new PostgreSqlPlatform() : new MySqlPlatform();
    }

    public static function setUpBeforeClass(): void
    {
        [$dsn, $user, $password] = self::isPostgres()
            ? [getenv('POSTGRES_DSN'), getenv('POSTGRES_USER'), getenv('POSTGRES_PASSWORD')]
            : [getenv('MYSQL_DSN'), getenv('MYSQL_USER'), getenv('MYSQL_PASSWORD')];

        if (!is_string($dsn) || $dsn === '') {
            throw new RuntimeException(self::dbEngine().' DSN not set');
        }

        if (!str_contains($dsn, '_test')) {
            throw new RuntimeException('Refusing to run: database name must contain "_test"');
        }

        self::$pdo = new PDO($dsn, $user ?: '', $password ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        // Always recreate the full schema so column types stay in sync with the
        // current DDL (e.g. CHAR vs VARCHAR on PG); order matters for FK safety.
        self::$pdo->exec('DROP TABLE IF EXISTS dead_letters');
        self::$pdo->exec('DROP TABLE IF EXISTS message_deduplication');
        self::$pdo->exec('DROP TABLE IF EXISTS outbox_messages');
        foreach (static::platform()->schemaSql(static::outboxFormat()) as $ddl) {
            self::$pdo->exec($ddl);
        }
    }

    protected function setUp(): void
    {
        self::$pdo->exec('DELETE FROM outbox_messages');
        self::$pdo->exec('DELETE FROM message_deduplication');
        self::$pdo->exec('DELETE FROM dead_letters');
    }

    protected static function fetchInt(string $sql): int
    {
        $statement = self::$pdo->query($sql);
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }
}
