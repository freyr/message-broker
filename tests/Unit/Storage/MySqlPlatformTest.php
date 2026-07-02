<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Storage;

use Freyr\MessageBroker\Storage\MySqlPlatform;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MySqlPlatformTest extends TestCase
{
    public function testReadBodyReturnsString(): void
    {
        self::assertSame('{"a":1}', (new MySqlPlatform())->readBody('{"a":1}'));
    }

    public function testReadBodyRejectsNonString(): void
    {
        $this->expectException(RuntimeException::class);
        (new MySqlPlatform())->readBody(null);
    }

    public function testReleaseLaneUsesReleaseLock(): void
    {
        self::assertStringContainsString(
            "RELEASE_LOCK(CONCAT('outbox:', :lane))",
            (new MySqlPlatform())->releaseLaneSql(),
        );
    }

    public function testSelectClaimBatchSqlSkipsLockedEligibleRowsInIdOrder(): void
    {
        $sql = (new MySqlPlatform())->selectClaimBatchSql();

        self::assertStringContainsString('WHERE lane = :lane AND available_at <= :now', $sql);
        self::assertStringContainsString('ORDER BY id', $sql);
        self::assertStringContainsString('LIMIT :limit', $sql);
        self::assertStringContainsString('FOR UPDATE SKIP LOCKED', $sql);
    }

    public function testClaimRunsAtReadCommittedToAvoidGapLocks(): void
    {
        self::assertSame(
            'SET TRANSACTION ISOLATION LEVEL READ COMMITTED',
            (new MySqlPlatform())->claimIsolationSql(),
        );
    }
}
