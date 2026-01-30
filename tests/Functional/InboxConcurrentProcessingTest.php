<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Tests\Functional\Fixtures\TestEvent;
use Freyr\MessageBroker\Tests\Functional\Fixtures\TestEventHandler;

/**
 * Suite 3: Concurrent Processing (Basic) Tests.
 *
 * Tests basic concurrent scenarios: distinct message processing, duplicate detection.
 *
 * Note: These tests use sequential processing (not true parallelism).
 * True concurrent worker tests are deferred to Phase 4.
 */
final class InboxConcurrentProcessingTest extends FunctionalTestCase
{
    /**
     * Test 1: Two workers process distinct messages.
     *
     * Scenario: Publish 10 unique messages, verify all processed exactly once.
     * Expected: All 10 handlers invoked, 10 deduplication entries, no duplicates.
     *
     * Note: This test doesn't require true parallelism - sequential processing with distinct messages is sufficient.
     */
    public function testTwoWorkersProcessDistinctMessages(): void
    {
        // Given: 10 unique messages published to queue
        $messageIds = [];
        for ($i = 1; $i <= 10; $i++) {
            $messageId = Id::new()->__toString();
            $messageIds[] = $messageId;

            $this->publishToAmqp('test_inbox', [
                'type' => 'test.event.sent',
                'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([['messageId' => $messageId]]),
            ], [
                'id' => Id::new()->__toString(),
                'name' => "message-{$i}",
                'timestamp' => CarbonImmutable::now()->toIso8601String(),
            ]);
        }

        // When: Worker consumes all messages
        $this->consumeFromInbox(limit: 10);

        // Then: All 10 handlers invoked exactly
        $this->assertEquals(10, TestEventHandler::getInvocationCount(),
            'All 10 distinct messages should be processed');

        // And: Exactly 10 UNIQUE deduplication entries (no duplicates)
        $this->assertEquals(10, $this->getDeduplicationEntryCount(),
            'Should have exactly 10 dedup entries for 10 distinct messages');

        // And: Each messageId has exactly one deduplication entry
        foreach ($messageIds as $messageId) {
            $this->assertDeduplicationEntryExists($messageId);
        }

        // And: No failed messages (all processing succeeded)
        $this->assertEquals(0, $this->getTableRowCount('messenger_messages'),
            'No messages should fail during normal processing');

        // And: Queue is empty (all consumed and ACK'd)
        $this->assertQueueEmpty('test_inbox');
    }

    /**
     * Test 2: Duplicate message is skipped by second worker.
     *
     * Scenario: Process message with Worker 1, republish same messageId, process with Worker 2.
     * Expected: Handler invoked once, duplicate detected by second worker.
     */
    public function testDuplicateMessageIsSkippedBySecondWorker(): void
    {
        // Given: A message processed by first worker
        $messageId = Id::new()->__toString();
        $testEvent = new TestEvent(
            id: Id::new(),
            name: 'first-worker',
            timestamp: CarbonImmutable::now()
        );

        $this->publishToAmqp('test_inbox', [
            'type' => 'test.event.sent',
            'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([['messageId' => $messageId]]),
        ], [
            'id' => $testEvent->id->__toString(),
            'name' => $testEvent->name,
            'timestamp' => $testEvent->timestamp->toIso8601String(),
        ]);

        // When: First worker processes
        $this->consumeFromInbox(limit: 1);

        // Then: Handler invoked once
        $this->assertEquals(1, TestEventHandler::getInvocationCount());
        $this->assertDeduplicationEntryExists($messageId);

        // When: Same message republished (simulating redelivery)
        $this->publishToAmqp('test_inbox', [
            'type' => 'test.event.sent',
            'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([['messageId' => $messageId]]),
        ], [
            'id' => $testEvent->id->__toString(),
            'name' => $testEvent->name,
            'timestamp' => $testEvent->timestamp->toIso8601String(),
        ]);

        // When: Second worker processes (simulating different worker)
        $this->consumeFromInbox(limit: 1);

        // Then: Handler still invoked only once (duplicate skipped)
        $this->assertEquals(1, TestEventHandler::getInvocationCount(),
            'Handler must be invoked exactly once despite redelivery');

        // And: CRITICAL - EXACTLY one deduplication entry (not zero, not two)
        $dedupCount = $this->getDeduplicationEntryCount();
        $this->assertEquals(1, $dedupCount,
            "Expected exactly 1 dedup entry, found {$dedupCount}. Duplicate processing detected.");

        // And: Verify the specific messageId has deduplication entry
        $this->assertDeduplicationEntryExists($messageId);

        // And: Queue is empty (second message was ACK'd despite being duplicate)
        $this->assertQueueEmpty('test_inbox');

        // And: No failed messages (duplicate is not an error condition)
        $this->assertEquals(0, $this->getTableRowCount('messenger_messages'));
    }
}
