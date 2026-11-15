<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Console;

use Freyr\MessageBroker\Console\DlqShowCommand;
use Freyr\MessageBroker\Tests\Fixtures\DeadLetterFactory;
use Freyr\MessageBroker\Tests\Fixtures\InMemoryDeadLetterStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class DlqShowCommandTest extends TestCase
{
    public function testPrintsEveryFieldOfAReplayedDeadLetter(): void
    {
        $store = new InMemoryDeadLetterStore();
        $store->add(DeadLetterFactory::make(
            'dl-1',
            messageId: 'm-1',
            body: '{"raw":"bytes"}',
            headers: [
                'correlation_id' => 'c-1',
            ],
            replayedAt: 1_750_000_001_000,
        ));
        $tester = new CommandTester(new DlqShowCommand($store));

        $status = $tester->execute([
            'id' => 'dl-1',
        ]);

        self::assertSame(0, $status);
        self::assertSame(
            <<<'OUT'
                id:            dl-1
                source:        orders_q
                message_id:    m-1
                message_name:  order.placed
                attempts:      5
                failed_at:     2025-06-15 15:06:40.123
                replayed_at:   2025-06-15 15:06:41.000
                error_class:   RuntimeException
                error_message: boom
                headers:       {"correlation_id":"c-1"}
                body:
                {"raw":"bytes"}
                trace:
                #0 trace-line

                OUT,
            $tester->getDisplay(),
        );
    }

    public function testNotReplayedDeadLetterShowsADashForReplayedAt(): void
    {
        $store = new InMemoryDeadLetterStore();
        $store->add(DeadLetterFactory::make('dl-1'));
        $tester = new CommandTester(new DlqShowCommand($store));

        $tester->execute([
            'id' => 'dl-1',
        ]);

        self::assertStringContainsString("\nreplayed_at:   -\n", $tester->getDisplay());
        self::assertStringContainsString("\nheaders:       []\n", $tester->getDisplay());
    }

    public function testUnknownIdFails(): void
    {
        $tester = new CommandTester(new DlqShowCommand(new InMemoryDeadLetterStore()));

        $status = $tester->execute([
            'id' => 'nope',
        ]);

        self::assertSame(1, $status);
        self::assertSame("Dead letter not found.\n", $tester->getDisplay());
    }
}
