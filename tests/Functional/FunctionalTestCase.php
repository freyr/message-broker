<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\MessageBroker\Outbox\OutboxPartitionManager;
use Freyr\MessageBroker\Storage\Platform;
use Freyr\MessageBroker\Storage\PostgreSqlPlatform;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

abstract class FunctionalTestCase extends TestCase
{
    protected static PDO $pdo;

    /** @var array<string, true> */
    private static array $createdDatabases = [];

    protected static function platform(): Platform
    {
        return new PostgreSqlPlatform();
    }

    public static function setUpBeforeClass(): void
    {
        [$dsn, $user, $password] = self::resolveCredentials();

        self::$pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        // Always recreate the full schema so column types stay in sync with the
        // current DDL (e.g. CHAR vs VARCHAR on PG); order matters for FK safety.
        self::$pdo->exec('DROP TABLE IF EXISTS dead_letters');
        self::$pdo->exec('DROP TABLE IF EXISTS message_deduplication');
        self::$pdo->exec('DROP TABLE IF EXISTS outbox_messages');
        $leftovers = self::$pdo->query("SELECT relname FROM pg_class WHERE relkind = 'r' AND relname ~ '^outbox_messages_p[0-9]{8}$'");
        self::assertNotFalse($leftovers);
        foreach ($leftovers->fetchAll(PDO::FETCH_COLUMN) as $name) {
            self::$pdo->exec(sprintf('DROP TABLE "%s"', (string) $name)); // detached daily tables survive the parent
        }
        foreach (static::platform()->schemaSql() as $ddl) {
            self::$pdo->exec($ddl);
        }
    }

    protected function setUp(): void
    {
        // No DEFAULT partition: rows of today (and of tomorrow, past midnight) need theirs.
        (new OutboxPartitionManager(self::$pdo))->ensurePartitions(1);
        self::$pdo->exec('DELETE FROM outbox_messages');
        self::$pdo->exec('DELETE FROM message_deduplication');
        self::$pdo->exec('DELETE FROM dead_letters');
    }

    /**
     * Resolve and validate the PostgreSQL credentials. Refuses any DSN
     * whose database name lacks "_test" — a hard safety guard shared by every
     * connection this base opens.
     *
     * @return array{string, string, string}
     */
    private static function resolveCredentials(): array
    {
        [$dsn, $user, $password] = [getenv('POSTGRES_DSN'), getenv('POSTGRES_USER'), getenv('POSTGRES_PASSWORD')];

        if (!is_string($dsn) || $dsn === '') {
            throw new RuntimeException('POSTGRES_DSN not set');
        }

        if (!str_contains($dsn, '_test')) {
            throw new RuntimeException('Refusing to run: database name must contain "_test"');
        }

        $user = $user ?: '';
        $password = $password ?: '';
        $token = self::workerToken();
        if ($token !== null) {
            $dsn = self::workerDatabase($dsn, $user, $password, $token);
        }

        return [$dsn, $user, $password];
    }

    /**
     * The worker index of a parallel run: Infection sets TEST_TOKEN for each
     * mutant process. Null in a plain PHPUnit run.
     */
    private static function workerToken(): ?string
    {
        $token = getenv('TEST_TOKEN');

        return is_string($token) && preg_match('/^\d+$/', $token) === 1 ? $token : null;
    }

    /**
     * Each parallel worker gets its own database (<name>_t<token>), created
     * on first use, so that workers never see each other's rows.
     */
    private static function workerDatabase(string $dsn, string $user, string $password, string $token): string
    {
        if (preg_match('/dbname=([^;]+)/', $dsn, $match) !== 1) {
            throw new RuntimeException('POSTGRES_DSN has no dbname');
        }
        $database = "{$match[1]}_t{$token}";
        if (!isset(self::$createdDatabases[$database])) {
            $admin = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $exists = $admin->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
            $exists->execute([$database]);
            if ($exists->fetchColumn() === false) {
                $admin->exec(sprintf('CREATE DATABASE "%s"', $database));
            }
            self::$createdDatabases[$database] = true;
        }

        return str_replace("dbname={$match[1]}", "dbname={$database}", $dsn);
    }

    /**
     * A queue or exchange name of this worker: the shared name in a plain
     * PHPUnit run, <name>_t<token> in a parallel run.
     */
    protected static function isolated(string $name): string
    {
        $token = self::workerToken();

        return $token === null ? $name : "{$name}_t{$token}";
    }

    /** A fresh, independent PDO connection (for cross-session lock tests). */
    protected static function newConnection(): PDO
    {
        [$dsn, $user, $password] = self::resolveCredentials();

        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    protected static function fetchInt(string $sql): int
    {
        $statement = self::$pdo->query($sql);
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }
}
