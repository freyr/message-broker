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
                'X-Message-Id' => $messageId,
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

        // Verify the X-Message-Id header exists
        $this->assertArrayHasKey('X-Message-Id', $headers);

        // Verify it contains the correct message ID
        $this->assertEquals($messageId, $headers['X-Message-Id']);
    }
}
