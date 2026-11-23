<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Testing;

use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeliveryRetention;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeliverySchemaInstaller;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxRetention;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlSchemaInstaller;
use Freyr\MessageBroker\Testing\TestIsolation;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The database and queue-name set-up that every functional test base shares: one PDO connection,
 * the schema recreated per class, the rows cleared per test, and the TEST_TOKEN isolation of
 * parallel Infection workers (database <name>_t<token>, names via isolated()).
 *
 * Used by the base class of each adapter test group, so that neither group imports a class
 * from the other. It needs the PostgreSQL adapter classes of src/ only. The transport-neutral
 * part (isolated(), the worker token) is {@see TestIsolation} of the core test kit.
 *
 * @phpstan-require-extends TestCase
 */
trait PostgreSqlTestSupport
{
    use TestIsolation;

    protected static PDO $pdo;

    /** @var array<string, true> */
    private static array $createdDatabases = [];

    /** A BYTEA column as pdo_pgsql returns it: a stream resource (or a string). */
    protected static function bodyOf(mixed $value): string
    {
        return is_resource($value) ? (string) stream_get_contents($value) : (string) $value;
    }

    protected static function schemaInstaller(): PostgreSqlSchemaInstaller
    {
        return new PostgreSqlSchemaInstaller(self::$pdo);
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
        self::$pdo->exec('DROP TABLE IF EXISTS webhook_deliveries');
        self::$pdo->exec('DROP TABLE IF EXISTS webhook_rate_limit');
        self::$pdo->exec('DROP TABLE IF EXISTS webhook_lanes');
        $leftovers = self::$pdo->query("SELECT relname FROM pg_class WHERE relkind = 'r' AND relname ~ '^(outbox_messages|webhook_deliveries)_p[0-9]{8}$'");
        self::assertNotFalse($leftovers);
        foreach ($leftovers->fetchAll(PDO::FETCH_COLUMN) as $name) {
            self::$pdo->exec(sprintf('DROP TABLE "%s"', (string) $name)); // detached daily tables survive the parent
        }
        self::schemaInstaller()->install(1);
        (new PostgreSqlDeliverySchemaInstaller(self::$pdo))->install(1);
    }

    protected function setUp(): void
    {
        // No DEFAULT partition: rows of today (and of tomorrow, past midnight) need theirs.
        (new PostgreSqlOutboxRetention(self::$pdo))->ensureCapacity(1);
        (new PostgreSqlDeliveryRetention(self::$pdo))->ensureCapacity(1);
        self::$pdo->exec('DELETE FROM webhook_deliveries');
        self::$pdo->exec('DELETE FROM outbox_messages');
        self::$pdo->exec('DELETE FROM message_deduplication');
        self::$pdo->exec('DELETE FROM dead_letters');
        self::$pdo->exec('DELETE FROM webhook_rate_limit');
        self::$pdo->exec('DELETE FROM webhook_lanes');
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

    /** @return array{string, string, string} DSN, user and password of this worker's test database */
    protected static function credentials(): array
    {
        return self::resolveCredentials();
    }

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
