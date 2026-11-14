<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Console;

use Freyr\MessageBroker\Console\SignalSupport;
use PHPUnit\Framework\TestCase;

final class SignalSupportTest extends TestCase
{
    public function testNoWarningWhenPcntlLoaded(): void
    {
        self::assertNull(SignalSupport::warning(true));
    }

    public function testWarningMentionsPcntlAndSigkillWhenMissing(): void
    {
        $warning = SignalSupport::warning(false);

        self::assertNotNull($warning);
        self::assertStringContainsString('pcntl', $warning);
        self::assertStringContainsString('SIGKILL', $warning);
    }
}
