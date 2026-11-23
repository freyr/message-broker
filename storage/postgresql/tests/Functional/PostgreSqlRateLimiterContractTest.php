<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlRateLimiter;
use Freyr\MessageBroker\Storage\PostgreSql\Testing\PostgreSqlTestSupport;
use Freyr\MessageBroker\Testing\RateLimiterContractTestCase;
use Freyr\MessageBroker\Webhook\RateLimiter;

/** The PostgreSQL rate limiter keeps the contract of the RateLimiter port. */
final class PostgreSqlRateLimiterContractTest extends RateLimiterContractTestCase
{
    use PostgreSqlTestSupport;

    protected function createRateLimiter(): RateLimiter
    {
        // A test can create two limiters and expects the buckets of each to start full.
        self::$pdo->exec('DELETE FROM webhook_rate_limit');

        return new PostgreSqlRateLimiter(self::$pdo);
    }

    protected function ageBuckets(int $milliseconds): void
    {
        $statement = self::$pdo->prepare("UPDATE webhook_rate_limit SET refilled_at = refilled_at - CAST(:ms AS INTEGER) * INTERVAL '1 millisecond'");
        $statement->execute([
            'ms' => $milliseconds,
        ]);
    }
}
