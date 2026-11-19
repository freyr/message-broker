<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Console;

use Freyr\MessageBroker\Console\WebhookRunCommand;
use Freyr\MessageBroker\Testing\FakeDeliverySender;
use Freyr\MessageBroker\Testing\InMemoryDeliveryStore;
use Freyr\MessageBroker\Testing\InMemorySubscriptionProvider;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Webhook\DeliveryCompletionKind;
use Freyr\MessageBroker\Webhook\DeliveryLoop;
use Freyr\MessageBroker\Webhook\DeliveryLoopConfig;
use Freyr\MessageBroker\Webhook\DeliveryRecord;
use Freyr\MessageBroker\Webhook\Subscription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** The command with a real DeliveryLoop over the fakes of the test kit: no database, no HTTP. */
final class WebhookRunCommandTest extends TestCase
{
    private MockClock $clock;
    private InMemoryDeliveryStore $store;
    private InMemorySubscriptionProvider $subscriptions;
    private FakeDeliverySender $sender;
    private int $sequence = 0;

    /** @var list<DeliveryLoopConfig> the config of each factory call */
    private array $configs = [];

    /** Stop the loop at the first claim, so a run with no limit ends after one pass. */
    private bool $stopAtFirstClaim = false;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-06-15 12:00:00 UTC');
        Clock::set($this->clock);
        $this->store = new InMemoryDeliveryStore();
        $this->subscriptions = new InMemorySubscriptionProvider();
        $this->sender = new FakeDeliverySender();
        $this->subscriptions->add(new Subscription('crm', 'https://crm.example/hook', ['whsec_x']), 'order.placed');
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
    }

    public function testTheNameIsTheWebhookRunCommand(): void
    {
        self::assertSame('message-broker:webhook:run', $this->command()->getName());
    }

    public function testLimitOneStopsAfterOneDeliveredRowWithExitCodeZero(): void
    {
        $first = $this->insert('crm');
        $second = $this->insert('crm');
        $tester = new CommandTester($this->command());

        $status = $tester->execute([
            '--limit' => '1',
            '--batch-size' => '1',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, $this->store->claimCalls, 'the loop stops at the end of the first pass');
        self::assertSame(DeliveryCompletionKind::Delivered, $this->store->outcome($first->id));
        self::assertNull($this->store->outcome($second->id), 'the second row is not delivered');
        self::assertStringContainsString('Delivering webhooks for all lanes — SIGTERM/SIGINT to stop.', $tester->getDisplay());
    }

    public function testTheOptionsReplaceTheirFieldsOfTheBaseConfigAndKeepTheOthers(): void
    {
        $this->stopAtFirstClaim = true;
        $base = new DeliveryLoopConfig(
            leaseMs: 5_000,
            batchSize: 50,
            limitForEachLane: 5,
            laneRefreshMs: 1_000,
            laneFilter: ['old'],
            maxAgeMs: 3_600_000,
            expiredCheckIntervalMs: 2_000,
            retentionDays: 9,
            idleSleepMs: 7,
        );
        $tester = new CommandTester($this->command($base));

        $status = $tester->execute([
            '--lane' => ['crm', 'erp', 'crm'],
            '--batch-size' => '10',
            '--lane-limit' => '3',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertEquals([new DeliveryLoopConfig(
            leaseMs: 5_000,
            batchSize: 10,
            limitForEachLane: 3,
            laneRefreshMs: 1_000,
            laneFilter: ['crm', 'erp'],
            maxAgeMs: 3_600_000,
            expiredCheckIntervalMs: 2_000,
            retentionDays: 9,
            idleSleepMs: 7,
        )], $this->configs);
        self::assertSame([[
            'lanes' => ['crm'],
            'limitForEachLane' => 3,
            'total' => 10,
            'leaseMs' => 5_000,
        ]], $this->store->claims, 'the loop claims with the config of the command');
        self::assertStringContainsString('Delivering webhooks for the lanes crm, erp — SIGTERM/SIGINT to stop.', $tester->getDisplay());
    }

    public function testNoOptionGivesTheBaseConfig(): void
    {
        $this->stopAtFirstClaim = true;
        $base = new DeliveryLoopConfig(batchSize: 40, limitForEachLane: 4, laneFilter: ['crm'], idleSleepMs: 1);

        $status = (new CommandTester($this->command($base)))->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertEquals([$base], $this->configs);
    }

    public function testTheDefaultBaseConfigIsTheDefaultOfTheLoop(): void
    {
        $this->stopAtFirstClaim = true;
        $command = new WebhookRunCommand(fn (DeliveryLoopConfig $config): DeliveryLoop => $this->loop($config));

        (new CommandTester($command))->execute([]);

        self::assertEquals([new DeliveryLoopConfig()], $this->configs);
    }

    public function testATimeLimitReachesTheLoop(): void
    {
        $this->store->onClaim = fn () => $this->clock->sleep(2);

        $status = (new CommandTester($this->command()))->execute([
            '--time-limit' => '1',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, $this->store->claimCalls, 'the time limit stops the loop after the first pass');
    }

    public function testAMemoryLimitReachesTheLoop(): void
    {
        $status = (new CommandTester($this->command()))->execute([
            '--memory-limit' => '1K',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, $this->store->claimCalls, 'the process uses more than 1 KiB, so the loop stops after the first pass');
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalidOptions(): iterable
    {
        yield 'limit zero' => [[
            '--limit' => '0',
        ], 'Invalid value for --limit'];
        yield 'limit trailing newline' => [[
            '--limit' => "1\n",
        ], 'Invalid value for --limit'];
        yield 'time limit text' => [[
            '--time-limit' => '10s',
        ], 'Invalid value for --time-limit'];
        yield 'memory limit unit' => [[
            '--memory-limit' => '12X',
        ], 'Invalid value for --memory-limit'];
        yield 'batch size zero' => [[
            '--batch-size' => '0',
        ], 'Invalid value for --batch-size'];
        yield 'batch size trailing newline' => [[
            '--batch-size' => "10\n",
        ], 'Invalid value for --batch-size'];
        yield 'batch size fraction' => [[
            '--batch-size' => '1.5',
        ], 'Invalid value for --batch-size'];
        yield 'lane limit negative' => [[
            '--lane-limit' => '-1',
        ], 'Invalid value for --lane-limit'];
        yield 'lane limit trailing newline' => [[
            '--lane-limit' => "3\n",
        ], 'Invalid value for --lane-limit'];
    }

    /** @param array<string, string> $options */
    #[DataProvider('invalidOptions')]
    public function testAnInvalidValueGivesExitCodeTwoAndNoLoopIsMade(array $options, string $message): void
    {
        $tester = new CommandTester($this->command());

        $status = $tester->execute($options);

        self::assertSame(Command::INVALID, $status);
        self::assertSame([], $this->configs);
        self::assertSame(0, $this->store->claimCalls);
        self::assertStringContainsString($message, $tester->getDisplay());
    }

    public function testTheOptionsOfTheCommand(): void
    {
        $definition = $this->command()->getDefinition();

        self::assertSame(
            ['lane', 'batch-size', 'lane-limit', 'limit', 'time-limit', 'memory-limit', 'require-signals'],
            array_keys($definition->getOptions()),
        );
        self::assertTrue($definition->getOption('lane')->isArray());
        self::assertSame([], $definition->getArguments());
    }

    public function testRequireSignalsRunsTheLoopWhenPcntlIsLoaded(): void
    {
        if (!extension_loaded('pcntl')) {
            self::markTestSkipped('ext-pcntl is not loaded');
        }
        $this->stopAtFirstClaim = true;

        $status = (new CommandTester($this->command()))->execute([
            '--require-signals' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, $this->store->claimCalls);
    }

    private function command(?DeliveryLoopConfig $base = null): WebhookRunCommand
    {
        return new WebhookRunCommand(
            fn (DeliveryLoopConfig $config): DeliveryLoop => $this->loop($config),
            $base ?? new DeliveryLoopConfig(idleSleepMs: 1),
        );
    }

    private function loop(DeliveryLoopConfig $config): DeliveryLoop
    {
        $this->configs[] = $config;
        $loop = new DeliveryLoop($this->store, $this->sender, $this->subscriptions, config: $config);
        if ($this->stopAtFirstClaim) {
            $this->store->onClaim = static fn () => $loop->stop();
        }

        return $loop;
    }

    private function insert(string $lane): DeliveryRecord
    {
        ++$this->sequence;
        $record = new DeliveryRecord(
            id: sprintf('01900000-0000-7000-8000-%012d', $this->sequence),
            messageId: sprintf('01900000-0000-7000-9000-%012d', $this->sequence),
            lane: $lane,
            messageName: 'order.placed',
            body: '{}',
            headers: [],
            createdAt: EpochMillis::now() - 1_000,
        );
        $this->store->insert([$record]);

        return $record;
    }
}
