<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Freyr\MessageBroker\Command\DeduplicationStoreCleanup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit test for DeduplicationStoreCleanup.
 *
 * Tests that the command:
 * - Uses platform-portable date arithmetic (PHP-computed cutoff)
 * - Falls back to 30 days for non-numeric input
 * - Passes custom tableName into SQL statement
 */
#[CoversClass(DeduplicationStoreCleanup::class)]
final class DeduplicationStoreCleanupTest extends TestCase
{
    #[Test]
    public function itUsesSpecifiedDaysValue(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('processed_at'),
                    $this->logicalNot($this->stringContains('DATE_SUB')),
                ),
                $this->callback(function (array $params): bool {
                    $this->assertCount(1, $params);
                    $this->assertInstanceOf(\DateTimeImmutable::class, $params[0]);

                    return true;
                }),
                $this->equalTo([Types::DATETIME_IMMUTABLE]),
            );

        $command = new DeduplicationStoreCleanup($connection);
        $tester = new CommandTester($command);
        $tester->execute([
            '--days' => '7',
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    #[Test]
    public function itFallsBackToDefaultForNonNumericDays(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->anything(),
                $this->callback(function (array $params): bool {
                    $this->assertInstanceOf(\DateTimeImmutable::class, $params[0]);

                    return true;
                }),
                $this->equalTo([Types::DATETIME_IMMUTABLE]),
            );

        $command = new DeduplicationStoreCleanup($connection);
        $tester = new CommandTester($command);
        $tester->execute([
            '--days' => 'abc',
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    #[Test]
    public function itFlowsCustomTableNameIntoSql(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('custom_dedup_table'), $this->anything(), $this->anything());

        $command = new DeduplicationStoreCleanup($connection, 'custom_dedup_table');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }
}
