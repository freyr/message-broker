<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;

/**
 * Debug test to inspect actual AMQP message headers.
 */
final class InboxHeaderDebugTest extends FunctionalTestCase
{
    public function testInspectActualAmqpHeaders(): void
    {
        // Given: Publish a message like the outbox does
        $messageId = Id::new()->__toString();
        $testId = Id::new();

        $this->publishToAmqp(
            'test_inbox',
            [
                'type' => 'test.event.sent',
                'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([
                    ['messageId' => $messageId],
                ]),
            ],
            [
                'id' => $testId->__toString(),
                'name' => 'debug-test',
                'timestamp' => CarbonImmutable::now()->toIso8601String(),
            ]
        );

        // When: We manually consume and inspect the message
        $message = $this->assertMessageInQueue('test_inbox');

        // Then: Print the actual headers for debugging
        $headers = $message['headers']->getNativeData();

        echo "\n=== AMQP Headers ===\n";
        foreach ($headers as $key => $value) {
            echo sprintf("%s: %s\n", $key, is_string($value) ? $value : json_encode($value));
        }
        echo "===================\n";

        // Verify the MessageIdStamp header exists
        $this->assertArrayHasKey('X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp', $headers);

        // Verify it can be decoded
        $stampData = json_decode($headers['X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp'], true);
        $this->assertIsArray($stampData);
        $this->assertEquals($messageId, $stampData[0]['messageId']);
    }
}
