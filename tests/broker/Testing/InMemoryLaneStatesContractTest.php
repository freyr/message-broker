<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Testing;

use Freyr\MessageBroker\Testing\InMemoryCircuitBreaker;
use Freyr\MessageBroker\Testing\LaneStatesContractTestCase;
use Freyr\MessageBroker\Webhook\CircuitBreaker;
use Freyr\MessageBroker\Webhook\CircuitBreakerPolicy;
use Freyr\MessageBroker\Webhook\LaneStates;

/** The in-memory circuit breaker keeps the contract of the lane states port: the proof that the fake is honest. */
final class InMemoryLaneStatesContractTest extends LaneStatesContractTestCase
{
    protected function createCircuitBreaker(CircuitBreakerPolicy $policy): CircuitBreaker&LaneStates
    {
        return new InMemoryCircuitBreaker($policy);
    }
}
