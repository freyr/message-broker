<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Console;

use Freyr\MessageBroker\Console\OutboxFailingCommand;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Testing\InMemoryOutboxStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** The command depends on the OutboxStore port only: the in-memory store stands in for PostgreSQL. */
final class OutboxFailingCommandTest extends TestCase
{
    private const int CREATED_AT = 1_767_225_600_123; // 2026-01-01 00:00:00.123 UTC

    private InMemoryOutboxStore $store;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->store = new InMemoryOutboxStore();
        $this->tester = new CommandTester(new OutboxFailingCommand($this->store));
    }

    public function testPrintsOneTableRowForEachFailingRowWithTheBodySizeAndNoBody(): void
    {
        $this->insert('01900000-0000-7000-8000-000000000001', attempts: 3, body: '{"secret":"x"}');
        $this->insert('01900000-0000-7000-8000-000000000002', attempts: 5, name: 'order.cancelled', createdAt: self::CREATED_AT + 1_000);

        $status = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $status, 'rows are not an error');
        self::assertSame(
            <<<'TXT'
                +--------------------------------------+---------+-----------------+----------+-------------------------+-------------------------+------------+
                | id                                   | lane    | message_name    | attempts | created_at              | next_attempt_at         | body_bytes |
                +--------------------------------------+---------+-----------------+----------+-------------------------+-------------------------+------------+
                | 01900000-0000-7000-8000-000000000002 | default | order.cancelled | 5        | 2026-01-01 00:00:01.123 | 2026-01-01 00:05:01.123 | 2          |
                | 01900000-0000-7000-8000-000000000001 | default | order.placed    | 3        | 2026-01-01 00:00:00.123 | 2026-01-01 00:05:00.123 | 14         |
                +--------------------------------------+---------+-----------------+----------+-------------------------+-------------------------+------------+

                TXT,
            $this->tester->getDisplay(),
        );
        self::assertStringNotContainsString('secret', $this->tester->getDisplay());
    }

    public function testDefaultsAreTheDefaultLaneThreeAttemptsAndOneHundredRows(): void
    {
        for ($i = 0; $i < 101; ++$i) {
            $this->insert(sprintf('01900000-0000-7000-8000-%012d', $i), attempts: 3, createdAt: self::CREATED_AT + $i);
        }
        $this->insert('01900000-0000-7000-8000-00000000aaaa', attempts: 2);
        $this->insert('01900000-0000-7000-8000-00000000bbbb', attempts: 9, lane: 'other');

        $this->tester->execute([]);

        $display = $this->tester->getDisplay();
        self::assertSame(100, substr_count($display, '| default |'), 'at most 100 rows of the lane "default"');
        self::assertStringContainsString('01900000-0000-7000-8000-000000000099', $display);
        self::assertStringNotContainsString('01900000-0000-7000-8000-000000000100', $display, 'the newest row is the 101st');
        self::assertStringNotContainsString('aaaa', $display, 'below 3 attempts');
        self::assertStringNotContainsString('bbbb', $display, 'another lane');
    }

    public function testOptionsReachTheStore(): void
    {
        $this->insert('01900000-0000-7000-8000-000000000001', attempts: 1, lane: 'billing');
        $this->insert('01900000-0000-7000-8000-000000000002', attempts: 2, lane: 'billing', createdAt: self::CREATED_AT + 1);
        $this->insert('01900000-0000-7000-8000-000000000003', attempts: 2, lane: 'billing', createdAt: self::CREATED_AT + 2);
        $this->insert('01900000-0000-7000-8000-000000000004', attempts: 9);

        $status = $this->tester->execute([
            '--lane' => 'billing',
            '--min-attempts' => '1',
            '--limit' => '2',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('01900000-0000-7000-8000-000000000002', $display);
        self::assertStringContainsString('01900000-0000-7000-8000-000000000003', $display);
        self::assertStringNotContainsString('01900000-0000-7000-8000-000000000001', $display, 'the limit of 2');
        self::assertStringNotContainsString('01900000-0000-7000-8000-000000000004', $display, 'another lane');
    }

    public function testNoFailingRowGivesOneLineAndSuccess(): void
    {
        $this->insert('01900000-0000-7000-8000-000000000001', attempts: 2, lane: 'billing');

        $status = $this->tester->execute([
            '--lane' => 'billing',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame("No open outbox row in lane billing with 3 or more attempts.\n", $this->tester->getDisplay());
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidOptions(): iterable
    {
        foreach (['--min-attempts', '--limit'] as $option) {
            yield "{$option} zero" => [$option, '0'];
            yield "{$option} negative" => [$option, '-1'];
            yield "{$option} text" => [$option, 'abc'];
            yield "{$option} trailing text" => [$option, '1x'];
            yield "{$option} leading text" => [$option, 'x1'];
            yield "{$option} decimal" => [$option, '1.5'];
            yield "{$option} empty" => [$option, ''];
        }
    }

    #[DataProvider('invalidOptions')]
    public function testAnOptionThatIsNotAnIntegerOfOneOrMoreIsInvalid(string $option, string $value): void
    {
        $this->insert('01900000-0000-7000-8000-000000000001', attempts: 3);

        $status = $this->tester->execute([
            $option => $value,
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertSame("--min-attempts and --limit must be integers of 1 or more.\n", $this->tester->getDisplay());
    }

    private function insert(
        string $id,
        int $attempts,
        string $lane = 'default',
        string $name = 'order.placed',
        string $body = '{}',
        int $createdAt = self::CREATED_AT,
    ): void {
        $this->store->insert(new OutboxRecord($id, $lane, $name, $body, [], $createdAt, $attempts, $createdAt + 300_000));
    }
}
