<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Tests\Functional\Fixtures\OrderPlacedHandler;
use Freyr\MessageBroker\Tests\Functional\Fixtures\TestEventHandler;

/**
 * Functional tests for Inbox Pattern.
 *
 * Tests complete inbox flow:
 * 1. Message consumed from AMQP
 * 2. InboxSerializer translates semantic name to FQN
 * 3. DeduplicationMiddleware checks message_broker_deduplication table
 * 4. Handler executes (or skips if duplicate)
 * 5. Deduplication entry created atomically with handler execution
 */
final class InboxFlowTest extends FunctionalTestCase
{
    public function testMessageConsumedFromAmqpAndHandled(): void
    {
        // Given: A message published to AMQP with semantic name
        $messageId = Id::new()->__toString();
        $testId = Id::new()->__toString();

        $this->publishToAmqp(
            'test_inbox',
            [
                'type' => 'test.event.sent',
                'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([
                    [
                        'messageId' => $messageId,
                    ],
                ]),
            ],
            [
                'id' => $testId,
                'name' => 'inbox-test-event',
                'timestamp' => CarbonImmutable::now()->toIso8601String(),
            ]
        );

        // When: Worker consumes from inbox
        $this->consumeFromInbox(limit: 1);

        // Then: Handler was invoked
        $this->assertHandlerInvoked(TestEventHandler::class, 1);

        // And: Deduplication entry was created
        $this->assertDeduplicationEntryExists($messageId);

        // And: Message was ACK'd (no longer in queue)
        $this->assertQueueEmpty('test_inbox');
    }

    public function testDuplicateMessageIsNotProcessedTwice(): void
    {
        // Given: A message already processed (with deduplication entry)
        $messageId = Id::new()->__toString();
        $testId = Id::new()->__toString();

        $messagePayload = [
            'id' => $testId,
            'name' => 'duplicate-test-event',
            'timestamp' => CarbonImmutable::now()->toIso8601String(),
        ];

        $headers = [
            'type' => 'test.event.sent',
            'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([
                [
                    'messageId' => $messageId,
                ],
            ]),
        ];

        // First message
        $this->publishToAmqp('test_inbox', $headers, $messagePayload);
        $this->consumeFromInbox(limit: 1);

        // Verify first message was processed
        $this->assertHandlerInvoked(TestEventHandler::class, 1);
        $this->assertDeduplicationEntryExists($messageId);

        // When: Same message published again (duplicate)
        $this->publishToAmqp('test_inbox', $headers, $messagePayload);
        $this->consumeFromInbox(limit: 1);

        // Then: Handler was NOT invoked again (still 1 invocation total)
        $this->assertHandlerInvoked(TestEventHandler::class, 1);

        // And: Only one deduplication entry exists
        $this->assertEquals(1, $this->getDeduplicationEntryCount());

        // And: Duplicate message was ACK'd
        $this->assertQueueEmpty('test_inbox');
    }

    public function testSemanticNameTranslation(): void
    {
        // Given: A message with semantic name 'test.event.sent'
        $messageId = Id::new()->__toString();
        $testId = Id::new();
        $testName = 'semantic-translation-test';
        $timestamp = CarbonImmutable::now();

        $this->publishToAmqp(
            'test_inbox',
            [
                'type' => 'test.event.sent',  // Semantic name
                'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([
                    [
                        'messageId' => $messageId,
                    ],
                ]),
            ],
            [
                'id' => $testId->__toString(),
                'name' => $testName,
                'timestamp' => $timestamp->toIso8601String(),
            ]
        );

        // When: Message is consumed
        $this->consumeFromInbox(limit: 1);

        // Then: InboxSerializer translated semantic name to FQN
        // And: Handler received correctly typed object
        $lastMessage = TestEventHandler::getLastMessage();
        $this->assertNotNull($lastMessage, 'Handler should have received a message');
        $this->assertEquals($testName, $lastMessage->name);
        $this->assertEquals($testId->__toString(), $lastMessage->id->__toString());
    }

    public function testMessageFormatCorrectness(): void
    {
        // Given: An OrderPlaced message with value objects (UUIDs, timestamps, floats)
        $messageId = Id::new()->__toString();
        $orderId = Id::new();
        $customerId = Id::new();
        $totalAmount = 99.99;
        $placedAt = CarbonImmutable::now();

        $this->publishToAmqp(
            'test_inbox',
            [
                'type' => 'test.order.placed',
                'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([
                    [
                        'messageId' => $messageId,
                    ],
                ]),
            ],
            [
                'orderId' => $orderId->__toString(),
                'customerId' => $customerId->__toString(),
                'totalAmount' => $totalAmount,
                'placedAt' => $placedAt->toIso8601String(),
            ]
        );

        // When: Message is consumed
        $this->consumeFromInbox(limit: 1);

        // Then: Handler was invoked
        $this->assertHandlerInvoked(OrderPlacedHandler::class, 1);

        // And: Value objects were correctly deserialized
        $lastMessage = OrderPlacedHandler::getLastMessage();
        $this->assertNotNull($lastMessage, 'Handler should have received a message');

        // UUIDs deserialized as Id objects
        $this->assertInstanceOf(Id::class, $lastMessage->orderId);
        $this->assertEquals($orderId->__toString(), $lastMessage->orderId->__toString());
        $this->assertInstanceOf(Id::class, $lastMessage->customerId);
        $this->assertEquals($customerId->__toString(), $lastMessage->customerId->__toString());

        // Timestamps deserialized as CarbonImmutable
        $this->assertInstanceOf(CarbonImmutable::class, $lastMessage->placedAt);
        $this->assertEquals($placedAt->toIso8601String(), $lastMessage->placedAt->toIso8601String());

        // Numeric values preserved
        $this->assertSame($totalAmount, $lastMessage->totalAmount);
    }
}
