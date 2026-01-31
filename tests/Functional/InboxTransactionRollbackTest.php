<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Tests\Functional\Fixtures\TestEvent;
use Freyr\MessageBroker\Tests\Functional\Fixtures\ThrowingTestEventHandler;

/**
 * Suite 1: Handler Exception & Rollback Tests.
 *
 * Tests transactional guarantee: deduplication entry + handler logic commit/rollback atomically.
 *
 * NOTE: Tests verify doctrine_transaction middleware integration.
 * Configuration documented in: docs/solutions/test-failures/doctrine-transaction-middleware-orm-configuration.md
 *
 * Middleware stack:
 * 1. doctrine_transaction (priority 0) - Starts transaction
 * 2. DeduplicationMiddleware (priority -10) - Runs within transaction
 * 3. Handler execution - Runs within transaction
 * 4. Commit (success) or Rollback (exception)
 */
final class InboxTransactionRollbackTest extends FunctionalTestCase
{
    /**
     * Test 1: Handler exception rolls back deduplication entry.
     *
     * Scenario: Handler throws RuntimeException on first processing attempt.
     * Expected: Deduplication entry NOT committed (transaction rolled back), message remains in queue.
     */
    public function testHandlerExceptionRollsBackDeduplicationEntry(): void
    {
        // Given: A message with MessageIdStamp
        $messageId = Id::new()->__toString();
        $testEvent = new TestEvent(id: Id::new(), name: 'will-fail', timestamp: CarbonImmutable::now());

        // Configure handler to throw exception
        ThrowingTestEventHandler::throwOnNextInvocation(new \RuntimeException('Handler failure simulation'));

        // Publish to AMQP with MessageIdStamp
        $this->publishToAmqp('test_inbox', [
            'type' => 'test.event.sent',
            'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([[
                'messageId' => $messageId,
            ]]),
        ], [
            'id' => $testEvent->id->__toString(),
            'name' => $testEvent->name,
            'timestamp' => $testEvent->timestamp->toIso8601String(),
        ]);

        // When: Worker consumes message (handler throws)
        // doctrine_transaction middleware automatically wraps in transaction
        try {
            $this->consumeFromInbox(limit: 1);
        } catch (\Exception $e) {
            // Expected: Worker will throw exception, transaction will rollback
        }

        // Then: Handler was invoked exactly once
        $this->assertEquals(1, ThrowingTestEventHandler::getInvocationCount(),
            'Handler should be invoked once despite exception');

        // And: Handler received correct message data (verify payload integrity)
        $lastMessage = ThrowingTestEventHandler::getLastMessage();
        $this->assertNotNull($lastMessage);
        $this->assertSame('will-fail', $lastMessage->name);

        // And: NO deduplication entry exists (transaction rolled back)
        $this->assertNoDeduplicationEntryExists($messageId);

        // And: Message NOT in failed transport (should remain in queue for retry, not moved to failed)
        $this->assertEquals(0, $this->getTableRowCount('messenger_messages'),
            'Message should remain in queue for retry, not moved to failed transport');

        // And: All other tables clean (verify complete rollback)
        $this->assertEquals(0, $this->getTableRowCount('messenger_outbox'));
        $this->assertEquals(0, $this->getDeduplicationEntryCount());
    }

    /**
     * Test 2: Handler succeeds after retry.
     *
     * Scenario: Handler throws on first attempt, succeeds on retry.
     * Expected: Deduplication entry created only after success, handler invoked twice total.
     */
    public function testHandlerSucceedsAfterRetry(): void
    {
        // Given: A message that will be retried
        $messageId = Id::new()->__toString();
        $testEvent = new TestEvent(id: Id::new(), name: 'retry-success', timestamp: CarbonImmutable::now());

        // First attempt: Handler throws
        ThrowingTestEventHandler::throwOnNextInvocation(new \RuntimeException('First attempt fails'));

        $this->publishToAmqp('test_inbox', [
            'type' => 'test.event.sent',
            'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([[
                'messageId' => $messageId,
            ]]),
        ], [
            'id' => $testEvent->id->__toString(),
            'name' => $testEvent->name,
            'timestamp' => $testEvent->timestamp->toIso8601String(),
        ]);

        // When: First attempt (fails)
        try {
            $this->consumeFromInbox(limit: 1);
        } catch (\Exception $e) {
            // Expected
        }

        // Then: Handler invoked once
        $this->assertEquals(1, ThrowingTestEventHandler::getInvocationCount());

        // And: No deduplication entry (rolled back)
        $this->assertNoDeduplicationEntryExists($messageId);

        // Republish same message (simulating retry)
        $this->publishToAmqp('test_inbox', [
            'type' => 'test.event.sent',
            'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([[
                'messageId' => $messageId,
            ]]),
        ], [
            'id' => $testEvent->id->__toString(),
            'name' => $testEvent->name,
            'timestamp' => $testEvent->timestamp->toIso8601String(),
        ]);

        // When: Second attempt (succeeds - no exception configured)
        // doctrine_transaction middleware automatically wraps in transaction
        $this->consumeFromInbox(limit: 1);

        // Then: Handler invoked exactly twice total (once failed, once succeeded)
        $this->assertEquals(2, ThrowingTestEventHandler::getInvocationCount());

        // And: Last message processed has correct data (verify second attempt)
        $lastMessage = ThrowingTestEventHandler::getLastMessage();
        $this->assertSame('retry-success', $lastMessage->name);

        // And: Deduplication entry NOW exists (committed with successful handler execution)
        $this->assertDeduplicationEntryExists($messageId);

        // And: No messages in outbox or failed transport (cleanup verification)
        $this->assertEquals(0, $this->getTableRowCount('messenger_outbox'));
        $this->assertEquals(0, $this->getTableRowCount('messenger_messages'),
            'No failed messages after successful retry');

        // And: Queue is empty (message consumed and ACK'd)
        $this->assertQueueEmpty('test_inbox');
    }

    /**
     * Test 3: Multiple handler exceptions in sequence.
     *
     * Scenario: Handler fails 3 times in a row, succeeds on 4th attempt.
     * Expected: No deduplication entry until success, message processed correctly after retries.
     */
    public function testMultipleHandlerExceptionsInSequence(): void
    {
        // Given: A message that will fail 3 times
        $messageId = Id::new()->__toString();
        $testEvent = new TestEvent(id: Id::new(), name: 'multiple-retries', timestamp: CarbonImmutable::now());

        // Attempt 1-3: Failures
        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            ThrowingTestEventHandler::throwOnNextInvocation(new \RuntimeException("Attempt {$attempt} fails"));

            $this->publishToAmqp('test_inbox', [
                'type' => 'test.event.sent',
                'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([[
                    'messageId' => $messageId,
                ]]),
            ], [
                'id' => $testEvent->id->__toString(),
                'name' => $testEvent->name,
                'timestamp' => $testEvent->timestamp->toIso8601String(),
            ]);

            try {
                // doctrine_transaction middleware automatically wraps in transaction
                $this->consumeFromInbox(limit: 1);
            } catch (\Exception $e) {
                // Expected: Transaction rolls back
            }

            // Verify no deduplication entry after each failure
            $this->assertNoDeduplicationEntryExists($messageId);
        }

        // Attempt 4: Success
        $this->publishToAmqp('test_inbox', [
            'type' => 'test.event.sent',
            'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([[
                'messageId' => $messageId,
            ]]),
        ], [
            'id' => $testEvent->id->__toString(),
            'name' => $testEvent->name,
            'timestamp' => $testEvent->timestamp->toIso8601String(),
        ]);

        // doctrine_transaction middleware automatically wraps in transaction
        $this->consumeFromInbox(limit: 1);

        // Then: Handler invoked exactly 4 times total (3 failures + 1 success)
        $this->assertEquals(4, ThrowingTestEventHandler::getInvocationCount(),
            '4 invocations expected: 3 failures + 1 success');

        // And: Last message processed correctly (verify data integrity)
        $lastMessage = ThrowingTestEventHandler::getLastMessage();
        $this->assertSame('multiple-retries', $lastMessage->name);

        // And: Deduplication entry exists after success
        $this->assertDeduplicationEntryExists($messageId);

        // And: Cleanup verification (no stale data)
        $this->assertEquals(1, $this->getDeduplicationEntryCount(),
            'Exactly one deduplication entry after all retries');
        $this->assertEquals(0, $this->getTableRowCount('messenger_messages'),
            'No failed messages after successful retry');
    }
}
