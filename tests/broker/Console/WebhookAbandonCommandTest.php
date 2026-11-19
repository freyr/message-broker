<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Console;

use Freyr\MessageBroker\Console\WebhookAbandonCommand;
use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Testing\InMemoryDeliveryStore;
use Freyr\MessageBroker\Testing\RecordingEvents;
use Freyr\MessageBroker\Testing\RecordingLogger;
use Freyr\MessageBroker\Tests\Fixtures\FailingDeliveryStore;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Webhook\DeliveryCompletionKind;
use Freyr\MessageBroker\Webhook\DeliveryRecord;
use Freyr\MessageBroker\Webhook\DeliveryStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** The command depends on the DeliveryStore port only: no PDO, no PostgreSQL. */
final class WebhookAbandonCommandTest extends TestCase
{
    private const string DATE = '2026-01-10 00:00:00.000';

    private int $sequence = 0;
    private int $before;
    private InMemoryDeliveryStore $store;
    private RecordingEvents $events;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->before = EpochMillis::fromDateTime(new \DateTimeImmutable(self::DATE.' UTC'));
        $this->store = new InMemoryDeliveryStore();
        $this->events = new RecordingEvents();
        $this->logger = new RecordingLogger();
    }

    public function testTheNameIsTheWebhookAbandonCommand(): void
    {
        self::assertSame('message-broker:webhook:abandon', $this->command()->getName());
    }

    public function testARunAbandonsTheUnfinishedRowsBeforeTheDateOfEachLaneAndPrintsTheCountOfEachLane(): void
    {
        $crm = [$this->insert('crm', -1), $this->insert('crm', -86_400_000), $this->insert('crm', -5)];
        $erp = $this->insert('erp', -1);
        $young = $this->insert('crm', 0);
        $tester = new CommandTester($this->command());

        $status = $tester->execute([
            '--before' => '2026-01-10',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(
            "Unfinished deliveries created before 2026-01-10 00:00:00.000 UTC:\n"
            ."crm: 3 row(s) abandoned.\n"
            ."erp: 1 row(s) abandoned.\n"
            ."Total: 4 row(s) abandoned.\n",
            $tester->getDisplay(),
        );
        foreach ([...$crm, $erp] as $row) {
            self::assertSame(DeliveryCompletionKind::Dead, $this->store->outcome($row->id));
        }
        self::assertNull($this->store->outcome($young->id), 'created at the date: not before it');
        self::assertSame(
            ['abandoned'],
            array_values(array_unique(array_map(static fn (DeadLetter $d): string => $d->errorClass, $this->store->deadLetters()->list(new DeadLetterFilter())))),
        );
        self::assertSame([
            [
                'event' => BrokerEvents::WEBHOOK_ABANDONED,
                'context' => [
                    'lane' => 'crm',
                    'count' => 3,
                ],
            ],
            [
                'event' => BrokerEvents::WEBHOOK_ABANDONED,
                'context' => [
                    'lane' => 'erp',
                    'count' => 1,
                ],
            ],
        ], $this->events->records);
        self::assertSame([
            [
                'level' => LogLevel::WARNING,
                'message' => 'Webhook deliveries abandoned by an operator; they go to the dead letters',
                'context' => [
                    'lane' => 'crm',
                    'count' => 3,
                    'before' => $this->before,
                ],
            ],
            [
                'level' => LogLevel::WARNING,
                'message' => 'Webhook deliveries abandoned by an operator; they go to the dead letters',
                'context' => [
                    'lane' => 'erp',
                    'count' => 1,
                    'before' => $this->before,
                ],
            ],
        ], $this->logger->records);
    }

    public function testTheLaneOptionKeepsTheAbandonToTheGivenLanes(): void
    {
        $crm = $this->insert('crm', -1);
        $erp = $this->insert('erp', -1);
        $bi = $this->insert('bi', -1);
        $tester = new CommandTester($this->command());

        $status = $tester->execute([
            '--before' => '2026-01-10',
            '--lane' => ['erp', 'crm', 'erp'],
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(DeliveryCompletionKind::Dead, $this->store->outcome($crm->id));
        self::assertSame(DeliveryCompletionKind::Dead, $this->store->outcome($erp->id));
        self::assertNull($this->store->outcome($bi->id));
        self::assertStringContainsString("crm: 1 row(s) abandoned.\nerp: 1 row(s) abandoned.\nTotal: 2 row(s) abandoned.\n", $tester->getDisplay());
    }

    public function testADryRunPrintsTheCountsThatTheRunThenMovesAndChangesNothing(): void
    {
        $rows = [$this->insert('crm', -1), $this->insert('crm', -2), $this->insert('erp', -3)];
        $dryRun = new CommandTester($this->command());

        $status = $dryRun->execute([
            '--before' => '2026-01-10',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(
            "[dry-run] Unfinished deliveries created before 2026-01-10 00:00:00.000 UTC:\n"
            ."[dry-run] crm: 2 row(s) would be abandoned.\n"
            ."[dry-run] erp: 1 row(s) would be abandoned.\n"
            ."[dry-run] Total: 3 row(s) would be abandoned.\n",
            $dryRun->getDisplay(),
        );
        foreach ($rows as $row) {
            self::assertNull($this->store->outcome($row->id));
        }
        self::assertSame(0, $this->store->deadLetters()->count(new DeadLetterFilter()));
        self::assertSame([], $this->events->records);
        self::assertSame([], $this->logger->records);

        $run = new CommandTester($this->command());
        $run->execute([
            '--before' => '2026-01-10',
        ]);
        self::assertSame(
            str_replace(['[dry-run] ', 'would be abandoned'], ['', 'abandoned'], $dryRun->getDisplay()),
            $run->getDisplay(),
            'the run moves the counts of the dry run',
        );
    }

    public function testNoRowBeforeTheDateIsASuccessThatChangesNothing(): void
    {
        $young = $this->insert('crm', 0);
        $tester = new CommandTester($this->command());

        $status = $tester->execute([
            '--before' => '2026-01-10',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame("No unfinished delivery was created before 2026-01-10 00:00:00.000 UTC.\n", $tester->getDisplay());
        self::assertNull($this->store->outcome($young->id));
        self::assertSame([], $this->events->records);
    }

    public function testTheRunCallsTheAbandonInBatchesUntilNoRowIsLeft(): void
    {
        $rows = [$this->insert('crm', -1), $this->insert('crm', -2), $this->insert('crm', -3)];
        $store = new FailingDeliveryStore($this->store);
        $tester = new CommandTester(new WebhookAbandonCommand($store, $this->events, batchSize: 1));

        $tester->execute([
            '--before' => '2026-01-10',
        ]);

        foreach ($rows as $row) {
            self::assertSame(DeliveryCompletionKind::Dead, $this->store->outcome($row->id));
        }
        self::assertSame([
            [$this->before, ['crm'], 1],
            [$this->before, ['crm'], 1],
            [$this->before, ['crm'], 1],
            [$this->before, ['crm'], 1],
        ], $store->abandonCalls, 'one row for each call, and a last call that finds none');
        self::assertSame([[
            'event' => BrokerEvents::WEBHOOK_ABANDONED,
            'context' => [
                'lane' => 'crm',
                'count' => 3,
            ],
        ]], $this->events->records);
    }

    public function testTheDefaultBatchIsOneThousandRows(): void
    {
        $this->insert('crm', -1);
        $store = new FailingDeliveryStore($this->store);

        (new CommandTester(new WebhookAbandonCommand($store)))->execute([
            '--before' => '2026-01-10',
        ]);

        self::assertSame([[$this->before, ['crm'], 1_000], [$this->before, ['crm'], 1_000]], $store->abandonCalls);
    }

    public function testANumericLaneIsGivenToTheStoreAsAString(): void
    {
        $row = $this->insert('42', -1);
        $store = new FailingDeliveryStore($this->store);

        $status = (new CommandTester(new WebhookAbandonCommand($store)))->execute([
            '--before' => '2026-01-10',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(DeliveryCompletionKind::Dead, $this->store->outcome($row->id));
        self::assertSame([$this->before, ['42'], 1_000], $store->abandonCalls[0]);
    }

    public function testALaneWhoseRowsEndedAfterTheCountPrintsZeroAndRecordsNoEvent(): void
    {
        $inner = $this->store;
        $store = new class($inner) implements DeliveryStore {
            public function __construct(
                private readonly InMemoryDeliveryStore $inner,
            ) {}

            public function insert(array $records): void
            {
                $this->inner->insert($records);
            }

            public function claim(array $lanes, int $limitForEachLane, int $total, int $leaseMs): array
            {
                return $this->inner->claim($lanes, $limitForEachLane, $total, $leaseMs);
            }

            public function complete(array $outcomes): array
            {
                return $this->inner->complete($outcomes);
            }

            public function abandon(int $before, ?array $lanes, int $limit): int
            {
                return $this->inner->abandon($before, $lanes, $limit);
            }

            private int $counts = 0;

            public function countUnfinished(int $before, ?array $lanes): array
            {
                // A worker delivered the row after the first count.
                return ++$this->counts === 1 ? [
                    'crm' => 1,
                ] : $this->inner->countUnfinished($before, $lanes);
            }
        };
        $tester = new CommandTester(new WebhookAbandonCommand($store, $this->events, $this->logger));

        $status = $tester->execute([
            '--before' => '2026-01-10',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString("crm: 0 row(s) abandoned.\nTotal: 0 row(s) abandoned.\n", $tester->getDisplay());
        self::assertSame([], $this->events->records);
        self::assertSame([], $this->logger->records);
    }

    public function testRowsThatADifferentSessionLocksArePrintedAsLeftWithANoteToRunAgain(): void
    {
        $lockedRow = $this->insert('crm', -1);
        $inner = $this->store;
        // A worker locks the row of crm: each abandon() call skips it and returns 0.
        $store = new class($inner) implements DeliveryStore {
            public function __construct(
                private readonly InMemoryDeliveryStore $inner,
            ) {}

            public function insert(array $records): void
            {
                $this->inner->insert($records);
            }

            public function claim(array $lanes, int $limitForEachLane, int $total, int $leaseMs): array
            {
                return $this->inner->claim($lanes, $limitForEachLane, $total, $leaseMs);
            }

            public function complete(array $outcomes): array
            {
                return $this->inner->complete($outcomes);
            }

            public function abandon(int $before, ?array $lanes, int $limit): int
            {
                return $lanes === ['crm'] ? 0 : $this->inner->abandon($before, $lanes, $limit);
            }

            public function countUnfinished(int $before, ?array $lanes): array
            {
                return $this->inner->countUnfinished($before, $lanes);
            }
        };
        $erp = $this->insert('erp', -1);
        $tester = new CommandTester(new WebhookAbandonCommand($store, $this->events, $this->logger));

        $status = $tester->execute([
            '--before' => '2026-01-10',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(
            "Unfinished deliveries created before 2026-01-10 00:00:00.000 UTC:\n"
            ."crm: 0 row(s) abandoned.\n"
            ."crm: 1 row(s) left (locked by a different session); run the command again.\n"
            ."erp: 1 row(s) abandoned.\n"
            ."Total: 1 row(s) abandoned.\n",
            $tester->getDisplay(),
        );
        self::assertNull($this->store->outcome($lockedRow->id));
        self::assertSame(DeliveryCompletionKind::Dead, $this->store->outcome($erp->id));
    }

    /** @return iterable<string, array{string, string}> */
    public static function dates(): iterable
    {
        yield 'a date: midnight UTC' => ['2026-01-10', '2026-01-10 00:00:00.000'];
        yield 'a UTC time' => ['2026-01-10T12:30:15Z', '2026-01-10 12:30:15.000'];
        yield 'a time with an offset' => ['2026-01-10T12:30:15+02:00', '2026-01-10 10:30:15.000'];
        yield 'a time with no zone: UTC' => ['2026-01-10T12:30:15', '2026-01-10 12:30:15.000'];
    }

    #[DataProvider('dates')]
    public function testTheBeforeOptionTakesADateOrAnIsoTime(string $value, string $utc): void
    {
        $before = EpochMillis::fromDateTime(new \DateTimeImmutable($utc.' UTC'));
        $this->before = $before;
        $earlier = $this->insert('crm', -1);
        $atTheDate = $this->insert('crm', 0);
        $tester = new CommandTester($this->command());

        $status = $tester->execute([
            '--before' => $value,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringStartsWith("Unfinished deliveries created before {$utc} UTC:\n", $tester->getDisplay());
        self::assertSame(DeliveryCompletionKind::Dead, $this->store->outcome($earlier->id));
        self::assertNull($this->store->outcome($atTheDate->id));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidInput(): iterable
    {
        $message = '--before must be a date (2026-01-31) or an ISO 8601 time (2026-01-31T12:00:00Z), in UTC when it has no offset.';

        yield 'no --before' => [[], $message];
        yield 'an empty --before' => [[
            '--before' => '',
        ], $message];
        yield 'a single letter (a time zone for the PHP parser)' => [[
            '--before' => 'a',
        ], $message];
        yield 'a relative date' => [[
            '--before' => '-30 days',
        ], $message];
        yield 'a day that does not exist' => [[
            '--before' => '2026-02-30',
        ], $message];
        yield 'a space in place of T' => [[
            '--before' => '2026-01-10 12:00:00',
        ], $message];
        yield 'text after the date' => [[
            '--before' => '2026-01-10x',
        ], $message];
        yield 'an empty lane' => [[
            '--before' => '2026-01-10',
            '--lane' => ['crm', ''],
        ], '--lane must not be empty.'];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('invalidInput')]
    public function testInvalidInputIsRejectedAndChangesNothing(array $input, string $message): void
    {
        $row = $this->insert('crm', -86_400_000);
        $tester = new CommandTester($this->command());

        $status = $tester->execute($input);

        self::assertSame(Command::INVALID, $status);
        self::assertSame($message."\n", $tester->getDisplay());
        self::assertNull($this->store->outcome($row->id));
    }

    private function command(): WebhookAbandonCommand
    {
        return new WebhookAbandonCommand($this->store, $this->events, $this->logger);
    }

    /** @param int $offsetMs the created_at relative to the date of the test */
    private function insert(string $lane, int $offsetMs): DeliveryRecord
    {
        ++$this->sequence;
        $record = new DeliveryRecord(
            id: sprintf('01900000-0000-7000-8000-%012d', $this->sequence),
            messageId: sprintf('01900000-0000-7000-9000-%012d', $this->sequence),
            lane: $lane,
            messageName: 'order.placed',
            body: '{}',
            headers: [],
            createdAt: $this->before + $offsetMs,
        );
        $this->store->insert([$record]);

        return $record;
    }
}
