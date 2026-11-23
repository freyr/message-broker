<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Console;

use Freyr\MessageBroker\Console\OutboxAbandonCommand;
use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Outbox\ClaimOutcome;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Testing\InMemoryOutboxStore;
use Freyr\MessageBroker\Testing\RecordingEvents;
use Freyr\MessageBroker\Testing\RecordingLogger;
use Freyr\MessageBroker\Time\EpochMillis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** The command depends on the OutboxStore port only: the in-memory store stands in for PostgreSQL. */
final class OutboxAbandonCommandTest extends TestCase
{
    private const string ID = '01900000-0000-7000-8000-000000000001';

    private InMemoryOutboxStore $store;
    private RecordingEvents $events;
    private RecordingLogger $logger;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->store = new InMemoryOutboxStore();
        $this->events = new RecordingEvents();
        $this->logger = new RecordingLogger();
        $this->tester = new CommandTester(new OutboxAbandonCommand($this->store, $this->events, $this->logger));
    }

    public function testAbandonsTheRowAndRecordsTheEventAndAWarningWithTheReason(): void
    {
        $this->insert(self::ID);

        $status = $this->tester->execute([
            'id' => self::ID,
            '--reason' => 'body above the broker limit (406)',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame("Abandoned 1 outbox row(s) with ID 01900000-0000-7000-8000-000000000001: the relay will not publish it.\n", $this->tester->getDisplay());
        self::assertTrue($this->store->isAbandoned(self::ID));
        $context = [
            'message_id' => self::ID,
            'count' => 1,
            'reason' => 'body above the broker limit (406)',
        ];
        self::assertSame([[
            'event' => BrokerEvents::ABANDONED,
            'context' => $context,
        ]], $this->events->records);
        self::assertSame([[
            'level' => LogLevel::WARNING,
            'message' => 'Outbox row abandoned by an operator; it will not be published',
            'context' => $context,
        ]], $this->logger->records);
    }

    public function testTheCountOfTwoRowsWithOneIdAndNoReason(): void
    {
        $this->insert(self::ID, 5_000);
        $this->insert(self::ID, 1_000); // a replay row of the same message

        $status = $this->tester->execute([
            'id' => self::ID,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame("Abandoned 2 outbox row(s) with ID 01900000-0000-7000-8000-000000000001: the relay will not publish it.\n", $this->tester->getDisplay());
        self::assertSame([[
            'event' => BrokerEvents::ABANDONED,
            'context' => [
                'message_id' => self::ID,
                'count' => 2,
                'reason' => null,
            ],
        ]], $this->events->records);
        self::assertSame([
            'message_id' => self::ID,
            'count' => 2,
            'reason' => null,
        ], $this->logger->records[0]['context']);
    }

    public function testNoOpenRowGivesFailureAndNoEventAndNoWarning(): void
    {
        $this->insert(self::ID);
        $this->store->drainClaimed('default', 10, static fn (): ClaimOutcome => ClaimOutcome::published([self::ID]));

        $status = $this->tester->execute([
            'id' => self::ID,
            '--reason' => 'too late',
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame("No unpublished row with this ID: it is published, already abandoned, or does not exist.\n", $this->tester->getDisplay());
        self::assertSame([], $this->events->records);
        self::assertSame([], $this->logger->records);
    }

    /** @return iterable<string, array{string}> */
    public static function emptyIds(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
    }

    #[DataProvider('emptyIds')]
    public function testAnEmptyIdIsInvalid(string $id): void
    {
        $status = $this->tester->execute([
            'id' => $id,
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertSame("The ID must not be empty.\n", $this->tester->getDisplay());
        self::assertSame([], $this->events->records);
    }

    public function testWorksWithNoEventListenerAndNoLogger(): void
    {
        $this->insert(self::ID);
        $tester = new CommandTester(new OutboxAbandonCommand($this->store));

        $status = $tester->execute([
            'id' => self::ID,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertTrue($this->store->isAbandoned(self::ID));
    }

    private function insert(string $id, int $ageMs = 1_000): void
    {
        $this->store->insert(new OutboxRecord($id, 'default', 'order.placed', '{}', [], EpochMillis::now() - $ageMs));
    }
}
