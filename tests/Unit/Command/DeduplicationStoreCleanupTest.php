<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Command;

use Doctrine\DBAL\Connection;
use Freyr\MessageBroker\Command\DeduplicationStoreCleanup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit test for DeduplicationStoreCleanup.
 *
 * Tests that the command:
 * - Uses specified --days value in SQL parameter
 * - Falls back to 30 days for non-numeric input
 * - Passes custom tableName into SQL statement
 */
#[CoversClass(DeduplicationStoreCleanup::class)]
final class DeduplicationStoreCleanupTest extends TestCase
{
    public function testUsesSpecifiedDaysValue(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('DATE_SUB'), $this->equalTo([7]));

        $command = new DeduplicationStoreCleanup($connection);
        $tester = new CommandTester($command);
        $tester->execute([
            '--days' => '7',
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testNonNumericDaysFallsBackToDefault(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('DATE_SUB'), $this->equalTo([30]));

        $command = new DeduplicationStoreCleanup($connection);
        $tester = new CommandTester($command);
        $tester->execute([
            '--days' => 'abc',
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testCustomTableNameFlowsIntoSql(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('custom_dedup_table'), $this->anything());

        $command = new DeduplicationStoreCleanup($connection, 'custom_dedup_table');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }
}
