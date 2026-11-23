<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Testing;

use Freyr\MessageBroker\Testing\CircuitBreakerContractTestCase;
use Freyr\MessageBroker\Testing\InMemoryCircuitBreaker;
use Freyr\MessageBroker\Webhook\CircuitBreaker;
use Freyr\MessageBroker\Webhook\CircuitBreakerPolicy;

/** The in-memory circuit breaker keeps the contract of the port: the proof that the fake is honest. */
final class InMemoryCircuitBreakerContractTest extends CircuitBreakerContractTestCase
{
    protected function createCircuitBreaker(CircuitBreakerPolicy $policy): CircuitBreaker
    {
        return new InMemoryCircuitBreaker($policy);
    }
}
