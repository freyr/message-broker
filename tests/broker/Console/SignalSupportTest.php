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
            'ext-pcntl is not loaded: SIGTERM and SIGINT end the process at once, with no graceful shutdown. '
            .'The current delivery or batch is not completed; the broker redelivers the delivery, the relay publishes the batch again, and deduplication absorbs the repeat. '
            .'As PID 1 of a container, the process ignores SIGTERM and SIGINT until Kubernetes sends SIGKILL at the end of the termination grace period. '
            .'Load ext-pcntl in the container image.',
            SignalSupport::warning(false),
        );
    }
}
