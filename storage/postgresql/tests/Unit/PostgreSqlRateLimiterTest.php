<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Unit;

use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlRateLimiter;
use Freyr\MessageBroker\Webhook\Rate;
use Freyr\MessageBroker\Webhook\RateLimits;
use PDO;
use PHPUnit\Framework\TestCase;

final class PostgreSqlRateLimiterTest extends TestCase
{
    public function testACallWithNoBucketDividesTheTotalAndDoesNotTouchTheDatabase(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method(self::anything());
        $limiter = new PostgreSqlRateLimiter($pdo);
        $limits = new RateLimits(null, [
            'other' => new Rate(1, 5),
        ]);

        self::assertSame([
            'crm' => 3,
            '42' => 2,
        ], $limiter->acquire([
            'crm' => 3,
            '42' => 3,
        ], 5, $limits), 'the lane with a limit is not in the call');
        $limiter->giveBack([
            'crm' => 1,
        ], $limits);
    }
}
