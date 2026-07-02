<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Outbox;

use Freyr\MessageBroker\Outbox\ClaimOutcome;
use PHPUnit\Framework\TestCase;

final class ClaimOutcomeTest extends TestCase
{
    public function testPublishedCarriesIdsAndNoRetries(): void
    {
        $outcome = ClaimOutcome::published(['a', 'b']);

        self::assertSame(['a', 'b'], $outcome->publishedIds);
        self::assertSame([], $outcome->retryAtMs);
    }

    public function testRetryAllCarriesRetryTimesAndNoDeletions(): void
    {
        $outcome = ClaimOutcome::retryAll([
            'a' => 1_000,
            'b' => 2_000,
        ]);

        self::assertSame([], $outcome->publishedIds);
        self::assertSame([
            'a' => 1_000,
            'b' => 2_000,
        ], $outcome->retryAtMs);
    }
}
