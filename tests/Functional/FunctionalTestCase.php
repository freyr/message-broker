<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\MessageBroker\Serializer\Format;
use Freyr\MessageBroker\Storage\MySqlPlatform;
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

    public static function setUpBeforeClass(): void
    {
        $dsn = getenv('MYSQL_DSN') ?: throw new RuntimeException('MYSQL_DSN not set');

        if (!str_contains($dsn, '_test')) {
            throw new RuntimeException('Refusing to run: database name must contain "_test"');
        }

        self::$pdo = new PDO($dsn, getenv('MYSQL_USER') ?: '', getenv('MYSQL_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        // Force outbox_messages to THIS class's format (body column type
        // differs); dedup + dead_letters are format-independent (IF NOT EXISTS).
        self::$pdo->exec('DROP TABLE IF EXISTS outbox_messages');
        foreach ((new MySqlPlatform())->schemaSql(static::outboxFormat()) as $ddl) {
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
