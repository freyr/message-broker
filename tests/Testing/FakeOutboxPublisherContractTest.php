<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Testing;

use Freyr\MessageBroker\Outbox\OutboxPublisher;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Testing\FakeOutboxPublisher;
use Freyr\MessageBroker\Testing\OutboxPublisherContractTestCase;

/** The scripted publisher keeps the contract of the port while no id is refused. */
final class FakeOutboxPublisherContractTest extends OutboxPublisherContractTestCase
{
    private FakeOutboxPublisher $fake;

    protected function createPublisher(): OutboxPublisher
    {
        return $this->fake = new FakeOutboxPublisher(10);
    }

    protected function assertDelivered(array $records): void
    {
        $delivered = array_merge(...$this->fake->calls);
        $expected = array_map(static fn (OutboxRecord $record): string => $record->id, $records);
        self::assertEqualsCanonicalizing($expected, $delivered);
    }
}
