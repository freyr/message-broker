<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use PDO;

/**
 * Guard of the run through pgbouncer (`make test-pgbouncer`, CI job `pgbouncer`): it proves that
 * two consecutive transactions of one client run on two different server connections. Without
 * that, a statement kept across transactions never meets another server connection, and a green
 * run of the other suites through the pooler proves nothing.
 *
 * Skipped unless POSTGRES_ROTATING_POOLER=1 says that POSTGRES_DSN points to a rotating pooler.
 */
final class PoolerRotationGuardTest extends FunctionalTestCase
{
    public static function setUpBeforeClass(): void
    {
        if (getenv('POSTGRES_ROTATING_POOLER') !== '1') {
            self::markTestSkipped('POSTGRES_ROTATING_POOLER=1 is not set: POSTGRES_DSN is not a rotating pooler');
        }

        parent::setUpBeforeClass();
    }

    public function testConsecutiveTransactionsOfOneClientRunOnDifferentServerConnections(): void
    {
        // Warm the pool: two transactions open at the same time need two server connections.
        $first = self::newConnection();
        $second = self::newConnection();
        $first->beginTransaction();
        $second->beginTransaction();
        $firstPid = self::backendPid($first);
        $secondPid = self::backendPid($second);
        $first->commit();
        $second->commit();
        self::assertNotSame($firstPid, $secondPid, 'Two open transactions must hold two server connections');

        $client = self::newConnection();
        $client->beginTransaction();
        $before = self::backendPid($client);
        $client->commit();
        $client->beginTransaction();
        $after = self::backendPid($client);
        $client->commit();

        self::assertNotSame(
            $before,
            $after,
            'The pooler gave one client the same server connection for two consecutive transactions: '
            .'the run through it proves nothing (check pool_mode = transaction, server_round_robin = 1, min_pool_size >= 2)',
        );
    }

    private static function backendPid(PDO $connection): int
    {
        $statement = $connection->query('SELECT pg_backend_pid()');
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }
}
