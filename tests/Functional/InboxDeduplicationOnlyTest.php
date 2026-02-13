<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Inbox\DeduplicationStore;
use Freyr\MessageBroker\Tests\Functional\Fixtures\TestEventHandler;

/**
 * Focused test on deduplication only.
 */
final class InboxDeduplicationOnlyTest extends FunctionalTestCase
{
    public function testDeduplicationStoreDirectly(): void
    {
        // Given: A message ID
        $messageId = Id::new();
        $messageName = 'Freyr\MessageBroker\Tests\Functional\Fixtures\TestEvent';

        // When: We check if it's a duplicate (first time)
        /** @var DeduplicationStore $store */
        $store = $this->getContainer()
            ->get('Freyr\MessageBroker\Inbox\DeduplicationStore');
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

    public function testEndToEndDeduplication(): void
    {
        // Given: Same message published twice with same MessageIdStamp
        $messageId = Id::new()->__toString();
        $testId = Id::new();

        $headers = [
            'type' => 'test.event.sent',
            'X-Message-Stamp-Freyr\MessageBroker\Stamp\MessageIdStamp' => json_encode([[
                'messageId' => $messageId,
            ]]),
        ];

        $body = [
            'id' => $testId->__toString(),
            'name' => 'dedup-test',
            'timestamp' => CarbonImmutable::now()->toIso8601String(),
        ];

        // Publish first message
        $this->publishToAmqp('test_inbox', $headers, $body);

        // Publish second message (duplicate)
        $this->publishToAmqp('test_inbox', $headers, $body);

        // When: Worker consumes both messages
        $this->consumeFromInbox(limit: 2);

        // Then: Handler was invoked only once (duplicate skipped)
        $this->assertHandlerInvoked(TestEventHandler::class, 1);

        // And: Only one deduplication entry exists
        $this->assertEquals(1, $this->getDeduplicationEntryCount());
    }
}
