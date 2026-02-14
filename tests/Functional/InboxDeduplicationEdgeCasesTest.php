<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Contracts\DeduplicationStore;
use Freyr\MessageBroker\Tests\Functional\Fixtures\TestEventHandler;

/**
 * Deduplication edge case tests.
 *
 * Tests validation behaviour and store API:
 * - Direct DeduplicationStore API (isDuplicate insert + check)
 * - Invalid UUID format in MessageIdStamp (DeduplicationMiddleware validation)
 * - Unmapped message type headers (InboxSerializer validation)
 */
final class InboxDeduplicationEdgeCasesTest extends FunctionalTestCase
{
    /**
     * DeduplicationStore API: first call inserts, second call detects duplicate.
     */
    public function testDeduplicationStoreDirectly(): void
    {
        // Given: A message ID
        $messageId = Id::new();
        $messageName = 'Freyr\MessageBroker\Tests\Functional\Fixtures\TestEvent';

        // When: We check if it's a duplicate (first time)
        /** @var DeduplicationStore $store */
        $store = $this->getContainer()
            ->get('Freyr\MessageBroker\Contracts\DeduplicationStore');
        $isDuplicate1 = $store->isDuplicate($messageId, $messageName);

        // Then: It's not a duplicate (first occurrence)
        $this->assertFalse($isDuplicate1, 'First message should not be duplicate');

        // And: Deduplication entry was created
        $this->assertDeduplicationEntryExists((string) $messageId);

        // When: We check the same message again
        $isDuplicate2 = $store->isDuplicate($messageId, $messageName);

        // Then: It's detected as duplicate
        $this->assertTrue($isDuplicate2, 'Second message with same ID should be duplicate');

        // And: Still only one deduplication entry
        $this->assertEquals(1, $this->getDeduplicationEntryCount());
    }

    /**
     * Message with invalid UUID in MessageIdStamp is rejected.
     *
     * Scenario: MessageIdStamp contains non-UUID value (e.g., "not-a-uuid").
     * Expected: DeduplicationMiddleware validates and rejects before handler execution.
     */
    public function testMessageWithInvalidUuidInMessageIdStampIsRejected(): void
    {
        // Given: A message with invalid UUID in MessageIdStamp
        $this->publishMalformedAmqpMessage('test_inbox');

        // When: Worker attempts to consume
        try {
            $this->consumeFromInbox(limit: 1);
        } catch (\Exception $e) {
            // Worker may or may not propagate exception — that's implementation detail
        }

        // Then: Handler was NOT invoked (validation prevented execution)
        $this->assertEquals(0, TestEventHandler::getInvocationCount(),
            'Handler should not be invoked for message with invalid UUID');

        // And: No deduplication entry (validation failed before dedup check)
        $this->assertEquals(0, $this->getDeduplicationEntryCount(),
            'No dedup entry should exist — validation failed');
    }

    /**
     * Unmapped type header is rejected.
     *
     * Scenario: `type` header contains value not in `message_types` config.
     * Expected: InboxSerializer throws MessageDecodingFailedException with clear error message.
     */
    public function testUnmappedTypeHeaderIsRejected(): void
    {
        // Given: A message with unmapped type header
        $this->publishToAmqp('test_inbox', [
            'type' => 'unknown.event.name',
            self::MESSAGE_ID_STAMP_HEADER => json_encode([[
                'messageId' => Id::new()->__toString(),
            ]]),
        ], [
            'id' => Id::new()->__toString(),
            'name' => 'unmapped-test',
            'timestamp' => CarbonImmutable::now()->toIso8601String(),
        ]);

        // When: Worker attempts to consume
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
    }
}
