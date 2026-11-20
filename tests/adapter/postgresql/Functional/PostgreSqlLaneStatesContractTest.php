<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlCircuitBreaker;
use Freyr\MessageBroker\Adapter\PostgreSql\Testing\PostgreSqlTestSupport;
use Freyr\MessageBroker\Testing\LaneStatesContractTestCase;
use Freyr\MessageBroker\Webhook\CircuitBreaker;
use Freyr\MessageBroker\Webhook\CircuitBreakerPolicy;
use Freyr\MessageBroker\Webhook\LaneStates;

/** The PostgreSQL circuit breaker keeps the contract of the LaneStates port. */
final class PostgreSqlLaneStatesContractTest extends LaneStatesContractTestCase
{
    use PostgreSqlTestSupport;

    protected function createCircuitBreaker(CircuitBreakerPolicy $policy): CircuitBreaker&LaneStates
    {
        return new PostgreSqlCircuitBreaker(self::$pdo, $policy);
    }
}
