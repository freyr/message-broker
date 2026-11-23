<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlCircuitBreaker;
use Freyr\MessageBroker\Storage\PostgreSql\Testing\PostgreSqlTestSupport;
use Freyr\MessageBroker\Testing\CircuitBreakerContractTestCase;
use Freyr\MessageBroker\Webhook\CircuitBreaker;
use Freyr\MessageBroker\Webhook\CircuitBreakerPolicy;
use PDO;

/** The PostgreSQL circuit breaker keeps the contract of the CircuitBreaker port. */
final class PostgreSqlCircuitBreakerContractTest extends CircuitBreakerContractTestCase
{
    use PostgreSqlTestSupport;

    protected function createCircuitBreaker(CircuitBreakerPolicy $policy): CircuitBreaker
    {
        return new PostgreSqlCircuitBreaker(self::$pdo, $policy);
    }

    /** Two sessions, each with its own connection and breaker, call record() at the same time. */
    protected function recordAtTheSameTime(CircuitBreaker $breaker, array $first, array $second): array
    {
        $policy = $breaker->policy();
        $session = static fn (array $counts): \Closure => static fn (PDO $pdo) => (new PostgreSqlCircuitBreaker($pdo, $policy))->record($counts);

        [$firstResult, $secondResult] = ConcurrentSessions::run(self::newConnection(...), [$session($first), $session($second)]);

        return [$firstResult, $secondResult];
    }
}
