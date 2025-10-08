<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Integration;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Serializer\MessageNameSerializer;
use Freyr\MessageBroker\Inbox\Transport\DoctrineInboxConnection;

// NOTE: This test uses old custom transports that have been removed.
// It needs significant refactoring to work with the new simplified architecture.
use Freyr\MessageBroker\Tests\Fixtures\Consumer\OrderPlacedMessage;
use Freyr\MessageBroker\Tests\Fixtures\Consumer\SlaCalculationStartedMessage;
use Freyr\MessageBroker\Tests\Fixtures\Consumer\UserPremiumUpgradedMessage;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * Inbox Integration Test.
 *
 * Tests:
 * 1. Messages are saved to inbox table with message_id as PK
 * 2. Deduplication via INSERT IGNORE works
 * 3. MessageNameSerializer deserializes to typed PHP objects
 * 4. Inbox transport handles binary UUID properly
 */
final class InboxIntegrationTest extends IntegrationTestCase
{
    private DoctrineInboxConnection $inboxConnection;
    private DoctrineTransport $inboxTransport;
    private MessageNameSerializer $serializer;

    protected function setUp(): void
    {
        parent::setUp();

        // Message type mapping for tests
        $messageTypes = [
            'order.placed' => OrderPlacedMessage::class,
            'sla.calculation.started' => SlaCalculationStartedMessage::class,
            'user.premium.upgraded' => UserPremiumUpgradedMessage::class,
        ];

        $this->serializer = new MessageNameSerializer($messageTypes);

        // Create inbox transport with custom connection
        $config = Connection::buildConfiguration(
            'inbox://default?table_name=messenger_inbox&queue_name=inbox'
        );
        $this->inboxConnection = new DoctrineInboxConnection($config, $this->getConnection());
        $this->inboxTransport = new DoctrineTransport($this->inboxConnection, $this->serializer);
    }

    public function test_message_is_saved_to_inbox_table_with_message_id_as_pk(): void
    {
        // Given
        $messageId = Id::new();
        $payload = [
            'messageId' => Id::new(),
            'orderId' => Id::new()->__toString(),
            'customerId' => Id::new()->__toString(),
            'amount' => 100.50,
            'placedAt' => CarbonImmutable::now()->toIso8601String(),
        ];

        $inboxMessage = [
            'message_name' => 'order.placed',
            'message_id' => $messageId->__toString(),
            'payload' => $payload,
        ];

        $body = json_encode($inboxMessage);
        $headers = [
            'message_name' => 'order.placed',
            'message_id' => $messageId->__toString(),
        ];

        // When
        $this->inboxConnection->send($body, $headers);

        // Then
        $result = $this->getConnection()->fetchAssociative(
            'SELECT HEX(id) as id, body FROM messenger_inbox WHERE queue_name = ?',
            ['inbox']
        );

        $this->assertNotFalse($result);
        $this->assertIsArray($result);

        // Verify message_id is used as PK
        $expectedHex = strtoupper(str_replace('-', '', $messageId->__toString()));
        $this->assertEquals($expectedHex, $result['id']);
    }

    public function test_duplicate_message_ids_are_deduplicated_via_insert_ignore(): void
    {
        // Given - Same message_id sent twice
        $messageId = Id::new();
        $payload = [
            'messageId' => Id::new(),
            'orderId' => Id::new()->__toString(),
            'customerId' => Id::new()->__toString(),
            'amount' => 100.50,
            'placedAt' => CarbonImmutable::now()->toIso8601String(),
        ];

        $inboxMessage = [
            'message_name' => 'order.placed',
            'message_id' => $messageId->__toString(),
            'payload' => $payload,
        ];

        $body = json_encode($inboxMessage);
        $headers = [
            'message_name' => 'order.placed',
            'message_id' => $messageId->__toString(),
        ];

        // When - Send same message twice
        $this->inboxConnection->send($body, $headers);
        $this->inboxConnection->send($body, $headers);  // Duplicate

        // Then - Only one row in database
        $count = $this->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM messenger_inbox WHERE queue_name = ?',
            ['inbox']
        );

        $this->assertEquals(1, $count, 'Duplicate should be ignored via INSERT IGNORE');
    }

    public function test_typed_inbox_serializer_deserializes_to_php_objects(): void
    {
        // Given
        $messageId = Id::new();
        $orderId = Id::new();
        $customerId = Id::new();
        $placedAt = CarbonImmutable::now();

        $payload = [
            'messageId' => $messageId->__toString(),
            'orderId' => $orderId->__toString(),
            'customerId' => $customerId->__toString(),
            'amount' => 100.50,
            'placedAt' => $placedAt->toIso8601String(),
        ];

        $inboxMessage = [
            'message_name' => 'order.placed',
            'message_id' => Id::new()->__toString(),
            'payload' => $payload,
        ];

        $encoded = [
            'body' => json_encode($inboxMessage),
            'headers' => [
                'message_name' => 'order.placed',
            ],
        ];

        // When
        $envelope = $this->serializer->decode($encoded);
        $message = $envelope->getMessage();

        // Then
        $this->assertInstanceOf(OrderPlacedMessage::class, $message);
        $this->assertEquals($orderId->__toString(), $message->orderId->__toString());
        $this->assertEquals($customerId->__toString(), $message->customerId->__toString());
        $this->assertEquals(100.50, $message->amount);
        $this->assertEquals($placedAt->toIso8601String(), $message->placedAt->toIso8601String());
    }

    public function test_inbox_handles_multiple_different_message_types(): void
    {
        // Given - Different message types
        $messages = [
            [
                'message_name' => 'order.placed',
                'message_id' => Id::new()->__toString(),
                'payload' => [
                'messageId' => Id::new(),
                'orderId' => Id::new()->__toString(),
                    'customerId' => Id::new()->__toString(),
                    'amount' => 100.50,
                    'placedAt' => CarbonImmutable::now()->toIso8601String(),
                ],
            ],
            [
                'message_name' => 'sla.calculation.started',
                'message_id' => Id::new()->__toString(),
                'payload' => [
                    'slaId' => Id::new()->__toString(),
                    'ticketId' => Id::new()->__toString(),
                    'startedAt' => CarbonImmutable::now()->toIso8601String(),
                ],
            ],
            [
                'message_name' => 'user.premium.upgraded',
                'message_id' => Id::new()->__toString(),
                'payload' => [
                    'userId' => Id::new()->__toString(),
                    'plan' => 'enterprise',
                    'upgradedAt' => CarbonImmutable::now()->toIso8601String(),
                ],
            ],
        ];

        // When
        foreach ($messages as $msg) {
            $body = json_encode($msg);
            $headers = [
                'message_name' => $msg['message_name'],
                'message_id' => $msg['message_id'],
            ];
            $this->inboxConnection->send($body, $headers);
        }

        // Then
        $count = $this->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM messenger_inbox WHERE queue_name = ?',
            ['inbox']
        );

        $this->assertEquals(3, $count, 'All 3 different messages should be saved');
    }

    public function test_missing_message_id_throws_exception(): void
    {
        // Given - Message without message_id
        $body = json_encode([
            'message_name' => 'order.placed',
            'payload' => [],
        ]);
        $headers = [
            'message_name' => 'order.placed',
            // No message_id!
        ];

        // Then
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('message_id header is required');

        // When
        $this->inboxConnection->send($body, $headers);
    }
}
