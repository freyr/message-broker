<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Console;

use Freyr\MessageBroker\Console\SignalSupport;
use PHPUnit\Framework\TestCase;

final class SignalSupportTest extends TestCase
{
    public function testNoWarningWhenPcntlLoaded(): void
    {
        self::assertNull(SignalSupport::warning(true));
    }

    public function testWarningStatesBothCasesWhenPcntlMissing(): void
    {
        self::assertSame(
            'ext-pcntl is not loaded: there is no graceful shutdown. '
            .'As PID 1 of a container, the process ignores SIGTERM and SIGINT until Kubernetes sends SIGKILL at the end of the termination grace period; as another PID, SIGTERM and SIGINT end it at once. '
            .'The current delivery, batch or webhook pass is not completed; the broker redelivers the delivery, the relay publishes the batch again, the webhook rows are due again after their lease, and deduplication absorbs the repeat. '
            .'Load ext-pcntl in the container image.',
            SignalSupport::warning(false),
        );
    }
}
