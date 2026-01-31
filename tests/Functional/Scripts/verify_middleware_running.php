<?php

declare(strict_types=1);

/**
 * Verify DeduplicationMiddleware is actually running.
 * Send same messageId twice - second should be skipped.
 */

require __DIR__.'/../../../vendor/autoload.php';

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Tests\Functional\Fixtures\TestEventHandler;
use Freyr\MessageBroker\Tests\Functional\{FunctionalTestCase};

class MiddlewareTest extends FunctionalTestCase
{
    public function testDup(): void
    {
        $messageId = Id::new()->__toString();

        // Send message twice with SAME messageId
        for ($i = 1; $i <= 2; ++$i) {
            $this->publishToAmqp('test_inbox', [
                'type' => 'test.event.sent',
                'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([[
                    'messageId' => $messageId,
                ]]),
            ], [
                'id' => Id::new()->__toString(),
                'name' => "attempt-{$i}",
                'timestamp' => CarbonImmutable::now()->toIso8601String(),
            ]);
        }

        // Consume both messages
        $this->consumeFromInbox(limit: 2);

        $count = TestEventHandler::getInvocationCount();
        echo "Handler invoked: {$count} times\n";
        echo "Expected: 1 time (second message should be deduped)\n";

        if ($count === 2) {
            echo "❌ MIDDLEWARE NOT RUNNING: Both messages processed\n";
            exit(1);
        } elseif ($count === 1) {
            echo "✅ MIDDLEWARE IS RUNNING: Second message was deduplicated\n";
            exit(0);
        }
        echo "❓ UNEXPECTED: Handler invoked {$count} times\n";
        exit(2);
    }
}

$test = new MiddlewareTest();
$test->setUp();
$test->testDup();
