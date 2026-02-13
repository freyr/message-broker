<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Tests\Functional\Fixtures\TestEventHandler;

/**
 * Suite 2: Deduplication Edge Cases Tests.
 *
 * Tests validation behavior:
 * - Invalid UUID format in MessageIdStamp (DeduplicationMiddleware validation)
 * - Unmapped message type headers (InboxSerializer validation)
 * - Duplicate message detection during processing
 */
final class InboxDeduplicationEdgeCasesTest extends FunctionalTestCase
{
    /**
     * Test 2: Message with invalid UUID in MessageIdStamp is rejected.
     *
     * Scenario: MessageIdStamp contains non-UUID value (e.g., "not-a-uuid").
     * Expected: DeduplicationMiddleware validates and rejects before handler execution.
     */
    public function testMessageWithInvalidUuidInMessageIdStampIsRejected(): void
    {
        // Given: A message with invalid UUID in MessageIdStamp
        $this->publishMalformedAmqpMessage('test_inbox', ['invalidUuid']);

        // When: Worker attempts to consume
        // (Symfony Messenger worker handles exceptions internally via retry/failed strategy)
        try {
            $this->consumeFromInbox(limit: 1);
        } catch (\Exception $e) {
            // Worker may or may not propagate exception - that's implementation detail
        }

        // Then: Handler was NOT invoked (validation prevented execution)
        $this->assertEquals(0, TestEventHandler::getInvocationCount(),
            'Handler should not be invoked for message with invalid UUID');

        // And: No deduplication entry (validation failed before dedup check)
        $this->assertEquals(0, $this->getDeduplicationEntryCount(),
            'No dedup entry should exist - validation failed');

        // Note: Invalid UUID is caught by DeduplicationMiddleware's Id::fromString() validation
        // Message remains in AMQP queue for retry per Symfony Messenger retry strategy
    }

    /**
     * Test 3: Unmapped type header is rejected.
     *
     * Scenario: `type` header contains value not in `message_types` config (e.g., `unknown.event.name`).
     * Expected: InboxSerializer throws MessageDecodingFailedException with clear error message.
     */
    public function testUnmappedTypeHeaderIsRejected(): void
    {
        // Given: A message with unmapped type header
        $this->publishToAmqp('test_inbox', [
            'type' => 'unknown.event.name', // Not in message_types config
            'X-Message-Stamp-Freyr\MessageBroker\Contracts\MessageIdStamp' => json_encode([[
                'messageId' => Id::new()->__toString(),
            ]]),
        ], [
            'id' => Id::new()->__toString(),
            'name' => 'unmapped-test',
            'timestamp' => CarbonImmutable::now()->toIso8601String(),
        ]);

        // When: Worker attempts to consume (exception will be thrown)
        $exceptionThrown = false;
        $exceptionMessage = '';
        try {
            $this->consumeFromInbox(limit: 1);
        } catch (\Exception $e) {
            $exceptionThrown = true;
            $exceptionMessage = $e->getMessage();
        }

        // Then: Exception was thrown
        $this->assertTrue($exceptionThrown, 'Expected exception for unmapped message type');

        // And: Exception message mentions the unmapped type
        $this->assertStringContainsString('unknown.event.name', $exceptionMessage,
            'Exception should mention the unmapped type');

        // And: Exception suggests configuration
        $this->assertStringContainsString('message_broker.inbox.message_types', $exceptionMessage,
            'Exception should guide user to configuration');

        // And: Handler was NOT invoked
        $this->assertEquals(0, TestEventHandler::getInvocationCount());

        // Note: Message remains in AMQP queue for retry (Symfony Messenger retry strategy)
    }

    /**
     * Test 4: Duplicate message during first processing is detected.
     *
     * Scenario: Same messageId published twice before first is processed.
     * Expected: First processes normally, second detects duplicate.
     *
     * Note: This is a sequential test (not true concurrent), but verifies dedup logic works.
     */
    public function testDuplicateMessageDuringFirstProcessingIsDetected(): void
    {
        // Given: Same messageId published twice
        $messageId = Id::new()->__toString();

        for ($i = 1; $i <= 2; ++$i) {
            $this->publishToAmqp('test_inbox', [
                'type' => 'test.event.sent',
                'X-Message-Stamp-Freyr\MessageBroker\Contracts\MessageIdStamp' => json_encode([[
                    'messageId' => $messageId,
                ]]),
            ], [
                'id' => Id::new()->__toString(),
                'name' => "duplicate-test-{$i}",
                'timestamp' => CarbonImmutable::now()->toIso8601String(),
            ]);
        }

        // When: Worker consumes both messages
        $this->consumeFromInbox(limit: 2);

        // Then: Handler invoked EXACTLY once (duplicate skipped)
        $this->assertEquals(1, TestEventHandler::getInvocationCount(),
            'Handler must be invoked exactly once for duplicate messages');

        // And: EXACTLY one deduplication entry
        $this->assertEquals(1, $this->getDeduplicationEntryCount(),
            'Exactly one dedup entry - duplicate not inserted');

        // And: Both messages ACK'd (queue empty)
        $this->assertQueueEmpty('test_inbox');

        // And: No failed messages (duplicate is not an error)
        $this->assertEquals(0, $this->getTableRowCount('messenger_messages'));
    }
}
