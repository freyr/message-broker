<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Stamp\MessageIdStamp;

/**
 * Debug test to inspect actual AMQP message headers.
 */
final class InboxHeaderDebugTest extends FunctionalTestCase
{
    public function testInspectActualAmqpHeaders(): void
    {
        // Given: Publish a message with native stamp header
        $messageId = Id::new()->__toString();
        $testId = Id::new();

        $this->publishToAmqp(
            'test_inbox',
            [
                'type' => 'test.event.sent',
                'X-Message-Stamp-' . MessageIdStamp::class => json_encode([['messageId' => $messageId]]),
            ],
            [
                'id' => $testId->__toString(),
                'name' => 'debug-test',
                'timestamp' => CarbonImmutable::now()->toIso8601String(),
            ]
        );

        // When: We manually consume and inspect the message
        $message = $this->assertMessageInQueue('test_inbox');

        // Then: Verify the actual headers
        $headers = $message['headers']->getNativeData();

        // Verify the native stamp header exists
        $stampHeaderKey = 'X-Message-Stamp-' . MessageIdStamp::class;
        $this->assertArrayHasKey($stampHeaderKey, $headers);

        // Verify it contains the correct message ID
        $stampData = json_decode($headers[$stampHeaderKey], true);
        $this->assertIsArray($stampData);
        $this->assertEquals($messageId, $stampData[0]['messageId']);
    }
}
