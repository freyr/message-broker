<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Tests\Functional\Fixtures\TestEvent;
use Freyr\MessageBroker\Tests\Functional\Fixtures\TestEventHandler;

/**
 * Suite 2: Deduplication Edge Cases Tests.
 *
 * Tests deduplication resilience: missing stamps, invalid UUIDs, malformed messages.
 */
final class InboxDeduplicationEdgeCasesTest extends FunctionalTestCase
{
    /**
     * Test 1: Message without MessageIdStamp is rejected.
     *
     * Scenario: AMQP message missing X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp header.
     * Expected: Message rejected, moved to failed transport, handler not invoked.
     *
     * SKIPPED: Requires product decision - should missing MessageIdStamp reject message or just skip deduplication?
     * See GitHub issue for discussion.
     */
    public function testMessageWithoutMessageIdStampIsRejected(): void
    {
        $this->markTestSkipped('Product decision needed: Should missing MessageIdStamp reject message or allow processing without deduplication? See GitHub issue.');


        // Given: A message without MessageIdStamp header
        $this->publishMalformedAmqpMessage('test_inbox', ['missingMessageId']);

        // When: Worker attempts to consume
        try {
            $this->consumeFromInbox(limit: 1);
        } catch (\Exception $e) {
            // May throw during deserialization
        }

        // Then: Handler was NOT invoked (exactly 0, not "at least 0")
        $this->assertEquals(0, TestEventHandler::getInvocationCount(),
            'Handler should not be invoked for message without MessageIdStamp');

        // And: Message is SPECIFICALLY in FAILED transport (not retry queue)
        $failedMessage = $this->assertMessageInFailedTransport(TestEvent::class);

        // And: Error metadata indicates missing MessageIdStamp
        $this->assertArrayHasKey('X-Message-Error', $failedMessage['headers']);
        $this->assertStringContainsString('MessageIdStamp', $failedMessage['headers']['X-Message-Error'][0],
            'Error should mention missing MessageIdStamp');

        // And: No deduplication entry created (message never reached middleware)
        $this->assertEquals(0, $this->getDeduplicationEntryCount(),
            'No dedup entry should exist for rejected message');
    }

    /**
     * Test 2: Message with invalid UUID in MessageIdStamp is rejected.
     *
     * Scenario: MessageIdStamp contains non-UUID value (e.g., "not-a-uuid").
     * Expected: Message rejected with validation error, moved to failed transport.
     *
     * SKIPPED: Requires validation implementation in DeduplicationMiddleware.
     * See GitHub issue for discussion.
     */
    public function testMessageWithInvalidUuidInMessageIdStampIsRejected(): void
    {
        $this->markTestSkipped('UUID validation not yet implemented in DeduplicationMiddleware. See GitHub issue.');


        // Given: A message with invalid UUID in MessageIdStamp
        $this->publishMalformedAmqpMessage('test_inbox', ['invalidUuid']);

        // When: Worker attempts to consume
        try {
            $this->consumeFromInbox(limit: 1);
        } catch (\Exception $e) {
            // Expected: Validation exception during deserialization
        }

        // Then: Handler was NOT invoked
        $this->assertEquals(0, TestEventHandler::getInvocationCount(),
            'Handler should not be invoked for message with invalid UUID');

        // And: Message in failed transport with error details
        $failedMessage = $this->assertMessageInFailedTransport(TestEvent::class);

        // And: Error indicates UUID validation failure
        $this->assertArrayHasKey('X-Message-Error', $failedMessage['headers']);
        $errorMessage = $failedMessage['headers']['X-Message-Error'][0];
        $this->assertTrue(
            str_contains($errorMessage, 'UUID') || str_contains($errorMessage, 'invalid'),
            'Error should mention UUID validation failure'
        );

        // And: No deduplication entry (validation failed before middleware)
        $this->assertEquals(0, $this->getDeduplicationEntryCount());
    }

    /**
     * Test 3: Invalid JSON body is rejected.
     *
     * Scenario: Message body contains malformed JSON.
     * Expected: Serialization exception, message moved to failed transport.
     *
     * SKIPPED: Requires InboxSerializer validation improvements.
     * See GitHub issue for discussion.
     */
    public function testInvalidJsonBodyIsRejected(): void
    {
        $this->markTestSkipped('InboxSerializer validation for malformed JSON not yet implemented. See GitHub issue.');


        // Given: A message with invalid JSON body
        $this->publishMalformedAmqpMessage('test_inbox', ['invalidJson']);

        // When: Worker attempts to consume
        try {
            $this->consumeFromInbox(limit: 1);
        } catch (\Exception $e) {
            // Expected: SerializationException during message deserialization
        }

        // Then: Handler was NOT invoked
        $this->assertEquals(0, TestEventHandler::getInvocationCount(),
            'Handler should not be invoked for message with malformed JSON');

        // And: Message in failed transport with JSON parse error
        $this->assertEquals(1, $this->getTableRowCount('messenger_messages'),
            'Exactly one message in failed transport');

        // Verify it's in the FAILED queue specifically
        $connection = $this->getContainer()->get('doctrine.dbal.default_connection');
        $queueName = $connection->fetchOne(
            "SELECT queue_name FROM messenger_messages WHERE queue_name = 'failed' LIMIT 1"
        );
        $this->assertEquals('failed', $queueName, 'Message must be in failed queue, not retry queue');

        // And: No deduplication entry (deserialization failed before dedup middleware)
        $this->assertEquals(0, $this->getDeduplicationEntryCount());
    }

    /**
     * Test 4: Missing type header is rejected.
     *
     * Scenario: AMQP message without semantic `type` header.
     * Expected: Cannot route to handler, message rejected to failed transport.
     *
     * SKIPPED: Requires InboxSerializer validation improvements.
     * See GitHub issue for discussion.
     */
    public function testMissingTypeHeaderIsRejected(): void
    {
        $this->markTestSkipped('InboxSerializer validation for missing type header not yet implemented. See GitHub issue.');


        // Given: A message without type header
        $this->publishMalformedAmqpMessage('test_inbox', ['missingType']);

        // When: Worker attempts to consume
        try {
            $this->consumeFromInbox(limit: 1);
        } catch (\Exception $e) {
            // Expected: Cannot translate semantic name to FQN
        }

        // Then: Handler NOT invoked
        $this->assertEquals(0, TestEventHandler::getInvocationCount(),
            'Handler cannot be invoked without type header');

        // And: Message in failed transport
        $this->assertEquals(1, $this->getTableRowCount('messenger_messages'));

        // And: Error indicates missing type header
        $connection = $this->getContainer()->get('doctrine.dbal.default_connection');
        $headers = $connection->fetchOne(
            "SELECT headers FROM messenger_messages WHERE queue_name = 'failed' LIMIT 1"
        );
        $this->assertNotFalse($headers);
        $this->assertStringContainsString('type', strtolower($headers),
            'Error should mention missing type header');
    }

    /**
     * Test 5: Unmapped type header is rejected.
     *
     * Scenario: `type` header contains value not in `message_types` config (e.g., `unknown.event.name`).
     * Expected: Cannot translate to FQN, clear error, failed transport.
     *
     * SKIPPED: Requires InboxSerializer validation improvements.
     * See GitHub issue for discussion.
     */
    public function testUnmappedTypeHeaderIsRejected(): void
    {
        $this->markTestSkipped('InboxSerializer validation for unmapped type header not yet implemented. See GitHub issue.');


        // Given: A message with unmapped type header
        $this->publishToAmqp('test_inbox', [
            'type' => 'unknown.event.name', // Not in message_types config
            'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([['messageId' => Id::new()->__toString()]]),
        ], [
            'id' => Id::new()->__toString(),
            'name' => 'unmapped-test',
            'timestamp' => CarbonImmutable::now()->toIso8601String(),
        ]);

        // When: Worker attempts to consume
        try {
            $this->consumeFromInbox(limit: 1);
        } catch (\Exception $e) {
            // Expected: InboxSerializer cannot map semantic name to FQN
        }

        // Then: Handler NOT invoked
        $this->assertEquals(0, TestEventHandler::getInvocationCount());

        // And: Message in failed transport with clear error
        $this->assertEquals(1, $this->getTableRowCount('messenger_messages'));

        // Verify error message clarity
        $connection = $this->getContainer()->get('doctrine.dbal.default_connection');
        $result = $connection->fetchAssociative(
            "SELECT headers FROM messenger_messages WHERE queue_name = 'failed' LIMIT 1"
        );
        $this->assertIsArray($result);
        // Error should mention the unmapped type
        $this->assertStringContainsString('unknown.event.name', $result['headers']);
    }

    /**
     * Test 6: Duplicate message during first processing is detected.
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

        for ($i = 1; $i <= 2; $i++) {
            $this->publishToAmqp('test_inbox', [
                'type' => 'test.event.sent',
                'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([['messageId' => $messageId]]),
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
