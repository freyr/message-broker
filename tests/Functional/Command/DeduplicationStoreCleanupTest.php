<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Command;

use Doctrine\DBAL\Connection;
use Freyr\MessageBroker\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Functional tests for the deduplication store cleanup command.
 *
 * Verifies the command correctly deletes old records while preserving recent ones.
 */
final class DeduplicationStoreCleanupTest extends FunctionalTestCase
{
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        parent::setUp();

        $application = new Application(self::$kernel);
        $command = $application->find('message-broker:deduplication-cleanup');
        $this->commandTester = new CommandTester($command);
    }

    public function testRemovesOldRecordsAndPreservesRecentOnes(): void
    {
        /** @var Connection $connection */
        $connection = $this->getContainer()->get('doctrine.dbal.default_connection');

        // Given: Two old records (31 days ago) and one recent record (1 day ago)
        $oldDate = (new \DateTimeImmutable('-31 days'))->format('Y-m-d H:i:s');
        $recentDate = (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');

        $connection->executeStatement(
            'INSERT INTO message_broker_deduplication (message_id, message_name, processed_at) VALUES (?, ?, ?)',
            [hex2bin('0195711a5bbb7000800000000000aa01'), 'old.event.one', $oldDate],
            [\Doctrine\DBAL\ParameterType::BINARY, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING]
        );

        $connection->executeStatement(
            'INSERT INTO message_broker_deduplication (message_id, message_name, processed_at) VALUES (?, ?, ?)',
            [hex2bin('0195711a5bbb7000800000000000aa02'), 'old.event.two', $oldDate],
            [\Doctrine\DBAL\ParameterType::BINARY, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING]
        );

        $connection->executeStatement(
            'INSERT INTO message_broker_deduplication (message_id, message_name, processed_at) VALUES (?, ?, ?)',
            [hex2bin('0195711a5bbb7000800000000000aa03'), 'recent.event', $recentDate],
            [\Doctrine\DBAL\ParameterType::BINARY, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING]
        );

        // Sanity check: 3 records exist
        $this->assertEquals(3, $this->getDeduplicationEntryCount());

        // When: Run cleanup with default 30 days
        $exitCode = $this->commandTester->execute(['--days' => 30]);

        // Then: Command succeeds
        $this->assertSame(Command::SUCCESS, $exitCode);

        // And: Only the recent record remains
        $this->assertEquals(1, $this->getDeduplicationEntryCount());

        // And: Output reports 2 deleted records
        $this->assertStringContainsString('2', $this->commandTester->getDisplay());
    }

    public function testEmptyTableProducesZeroDeletedOutput(): void
    {
        // Given: Empty deduplication table
        $this->assertEquals(0, $this->getDeduplicationEntryCount());

        // When: Run cleanup
        $exitCode = $this->commandTester->execute(['--days' => 30]);

        // Then: Command succeeds
        $this->assertSame(Command::SUCCESS, $exitCode);

        // And: Output reports 0 deleted
        $this->assertStringContainsString('0', $this->commandTester->getDisplay());
    }

    public function testCustomDaysOption(): void
    {
        /** @var Connection $connection */
        $connection = $this->getContainer()->get('doctrine.dbal.default_connection');

        // Given: A record from 3 days ago
        $threeDaysAgo = (new \DateTimeImmutable('-3 days'))->format('Y-m-d H:i:s');

        $connection->executeStatement(
            'INSERT INTO message_broker_deduplication (message_id, message_name, processed_at) VALUES (?, ?, ?)',
            [hex2bin('0195711a5bbb7000800000000000bb01'), 'three.day.old', $threeDaysAgo],
            [\Doctrine\DBAL\ParameterType::BINARY, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING]
        );

        // When: Run cleanup with --days=7 (record is newer than 7 days)
        $this->commandTester->execute(['--days' => 7]);

        // Then: Record is preserved (only 3 days old, threshold is 7)
        $this->assertEquals(1, $this->getDeduplicationEntryCount());

        // When: Run cleanup with --days=2 (record is older than 2 days)
        $this->commandTester->execute(['--days' => 2]);

        // Then: Record is deleted
        $this->assertEquals(0, $this->getDeduplicationEntryCount());
    }
}
