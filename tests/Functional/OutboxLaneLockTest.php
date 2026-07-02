<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\MessageBroker\Outbox\PdoOutboxStore;

/**
 * The lane advisory lock is exclusive across connections, and releaseLane()
 * frees it so another connection (a restarted/standby relay) can take over.
 */
final class OutboxLaneLockTest extends FunctionalTestCase
{
    public function testReleaseFreesTheLaneForAnotherConnection(): void
    {
        $owner = new PdoOutboxStore(self::$pdo, static::platform());
        $rival = new PdoOutboxStore(self::newConnection(), static::platform());

        self::assertTrue($owner->tryAcquireLane('orders'), 'owner acquires the free lane');
        self::assertFalse($rival->tryAcquireLane('orders'), 'rival is locked out while owner holds it');

        $owner->releaseLane('orders');

        self::assertTrue($rival->tryAcquireLane('orders'), 'rival acquires once the owner releases');
    }
}
