<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\MessageBroker\Storage\MySqlPlatform;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

abstract class FunctionalTestCase extends TestCase
{
    protected static PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        $dsn = getenv('MYSQL_DSN') ?: throw new RuntimeException('MYSQL_DSN not set');

        if (!str_contains($dsn, '_test')) {
            throw new RuntimeException('Refusing to run: database name must contain "_test"');
        }

        self::$pdo = new PDO($dsn, getenv('MYSQL_USER') ?: '', getenv('MYSQL_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        foreach ((new MySqlPlatform())->schemaSql() as $ddl) {
            self::$pdo->exec($ddl);
        }
    }

    protected function setUp(): void
    {
        self::$pdo->exec('DELETE FROM outbox_messages');
    }
}
