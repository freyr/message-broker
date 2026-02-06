<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Tests\Functional\Fixtures\TestEventHandler;

/**
 * Simple inbox deserialization test.
 *
 * Tests:
 * 1. Publish JSON message to AMQP with semantic name
 * 2. Consume via Worker
 * 3. Verify InboxSerializer translated semantic name to PHP class
 * 4. Verify handler received correctly deserialized object
 */
final class InboxDeserializationTest extends FunctionalTestCase
{
    public function testMessageDeserializationAndSemanticNameTranslation(): void
    {
        // Given: A JSON message published to AMQP with semantic name 'test.event.sent'
        $messageId = Id::new()->__toString();
        $testId = Id::new();
        $testName = 'deserialization-test';
        $timestamp = CarbonImmutable::parse('2026-01-29 12:00:00');

        // Publish raw JSON to AMQP queue
        $this->publishToAmqp(
            'test_inbox',
            [
                'type' => 'test.event.sent',  // Semantic name - should be translated to FQN
                'X-Message-Id' => $messageId,
            ],
            [
                'id' => $testId->__toString(),
                'name' => $testName,
                'timestamp' => $timestamp->toIso8601String(),
            ]
        );

        // When: Worker consumes the message
        $this->consumeFromInbox(limit: 1);

        // Then: Handler was invoked (semantic name translated to FQN)
        $this->assertHandlerInvoked(TestEventHandler::class, 1);

        // And: Handler received correctly deserialized object with all properties
        $receivedMessage = TestEventHandler::getLastMessage();
        $this->assertNotNull($receivedMessage, 'Handler should have received a message');

        // Verify all properties were correctly deserialized
        $this->assertEquals($testId->__toString(), $receivedMessage->id->__toString(), 'Id should match');
        $this->assertEquals($testName, $receivedMessage->name, 'Name should match');
        $this->assertEquals(
            $timestamp->toIso8601String(),
            $receivedMessage->timestamp->toIso8601String(),
            'Timestamp should match'
        );

        // Verify types
        $this->assertInstanceOf(Id::class, $receivedMessage->id, 'Id should be deserialized as Id object');
        $this->assertInstanceOf(
            CarbonImmutable::class,
            $receivedMessage->timestamp,
            'Timestamp should be deserialized as CarbonImmutable'
        );
    }
}
