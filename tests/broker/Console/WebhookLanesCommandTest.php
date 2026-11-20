<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Console;

use Freyr\MessageBroker\Console\WebhookLanesCommand;
use Freyr\MessageBroker\Testing\InMemoryCircuitBreaker;
use Freyr\MessageBroker\Webhook\CircuitBreakerPolicy;
use Freyr\MessageBroker\Webhook\LaneCounts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WebhookLanesCommandTest extends TestCase
{
    private MockClock $clock;

    private InMemoryCircuitBreaker $breaker;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-06-15 12:00:00 UTC');
        Clock::set($this->clock);
        $this->breaker = new InMemoryCircuitBreaker(new CircuitBreakerPolicy(minimumRequests: 1, openMs: 60_000, maxConsecutiveOpenings: 1));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
    }

    public function testTheNameIsTheWebhookLanesCommand(): void
    {
        self::assertSame('message-broker:webhook:lanes', (new WebhookLanesCommand($this->breaker))->getName());
    }

    public function testNoLaneStatePrintsANote(): void
    {
        $tester = new CommandTester(new WebhookLanesCommand($this->breaker));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame("No lane has a circuit breaker state.\n", $tester->getDisplay());
    }

    public function testTheListHasEachLaneWithItsStateCountersAndTimes(): void
    {
        $this->breaker->record([new LaneCounts('blocked', 1, 1), new LaneCounts('crm', 3, 1)]);
        $this->clock->sleep(2.75);
        $this->breaker->record([new LaneCounts('edge', 1, 1)]);
        $this->clock->sleep(58.25);
        $this->breaker->record([new LaneCounts('blocked', 1, 1)]);
        $this->clock->sleep(1.5);
        $this->breaker->record([new LaneCounts('open', 1, 1)]);
        $this->clock->sleep(0.25);
        $tester = new CommandTester(new WebhookLanesCommand($this->breaker));

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        // At 12:01:02.750: "edge" has open_until at that time, so it is in circulation.
        self::assertSame(<<<'TXT'
            +---------+----------------+-------------------------+----------+----------+-------------------------+-------------------------+--------------+----------------------+
            | lane    | state          | window_started_at       | requests | failures | open_until              | blocked_at              | opened_count | consecutive_openings |
            +---------+----------------+-------------------------+----------+----------+-------------------------+-------------------------+--------------+----------------------+
            | blocked | blocked        | 2026-06-15 12:01:01.000 | 0        | 0        | 2026-06-15 12:01:00.000 | 2026-06-15 12:01:01.000 | 2            | 2                    |
            | crm     | in circulation | 2026-06-15 12:00:00.000 | 3        | 1        | -                       | -                       | 0            | 0                    |
            | edge    | in circulation | 2026-06-15 12:00:02.750 | 0        | 0        | 2026-06-15 12:01:02.750 | -                       | 1            | 1                    |
            | open    | open           | 2026-06-15 12:01:02.500 | 0        | 0        | 2026-06-15 12:02:02.500 | -                       | 1            | 1                    |
            +---------+----------------+-------------------------+----------+----------+-------------------------+-------------------------+--------------+----------------------+

            TXT, $tester->getDisplay());
    }

    public function testCloseEndsTheBlockOfTheLane(): void
    {
        $this->breaker->record([new LaneCounts('crm', 1, 1)]);
        $this->clock->sleep(61);
        $this->breaker->record([new LaneCounts('crm', 1, 1)]);
        self::assertSame(['crm'], $this->breaker->openLanes());
        $tester = new CommandTester(new WebhookLanesCommand($this->breaker));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--close' => 'crm',
        ]));

        self::assertSame("The lane \"crm\" is in circulation again.\n", $tester->getDisplay());
        self::assertSame([], $this->breaker->openLanes());
    }

    public function testCloseOfALaneWithNoStateFails(): void
    {
        $this->breaker->record([new LaneCounts('crm', 1, 1)]);
        $tester = new CommandTester(new WebhookLanesCommand($this->breaker));

        self::assertSame(Command::FAILURE, $tester->execute([
            '--close' => 'erp',
        ]));

        self::assertSame("The lane \"erp\" has no circuit breaker state.\n", $tester->getDisplay());
        self::assertSame(['crm'], $this->breaker->openLanes());
    }

    public function testAnEmptyCloseIsInvalid(): void
    {
        $tester = new CommandTester(new WebhookLanesCommand($this->breaker));

        self::assertSame(Command::INVALID, $tester->execute([
            '--close' => '',
        ]));
        self::assertSame("--close must name a lane.\n", $tester->getDisplay());
    }
}
