<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Testing;

use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\Testing\DeliveryStoreContractTestCase;
use Freyr\MessageBroker\Testing\InMemoryDeadLetterStore;
use Freyr\MessageBroker\Testing\InMemoryDeliveryStore;
use Freyr\MessageBroker\Webhook\DeliveryStore;

/** The in-memory delivery store keeps the contract of the port: the proof that the fake is honest. */
final class InMemoryDeliveryStoreContractTest extends DeliveryStoreContractTestCase
{
    private InMemoryDeadLetterStore $deadLetters;

    protected function createStore(): DeliveryStore
    {
        $this->deadLetters = new InMemoryDeadLetterStore();

        return new InMemoryDeliveryStore($this->deadLetters);
    }

    protected function deadLetterStore(): DeadLetterStore
    {
        return $this->deadLetters;
    }
}
