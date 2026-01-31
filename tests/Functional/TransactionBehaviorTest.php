<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Tests\Functional\Fixtures\TestEvent;
use Freyr\MessageBroker\Tests\Functional\Fixtures\ThrowingTestEventHandler;

/**
 * Test to empirically verify transaction behavior.
 */
final class TransactionBehaviorTest extends FunctionalTestCase
{
    public function testVerifyActualTransactionBehavior(): void
    {
        // Given: A message that will cause handler to throw
        $messageId = Id::new()->__toString();
        $testEvent = new TestEvent(id: Id::new(), name: 'transaction-test', timestamp: CarbonImmutable::now());

        ThrowingTestEventHandler::throwOnNextInvocation(new \RuntimeException('Test exception'));

        $this->publishToAmqp('test_inbox', [
            'type' => 'test.event.sent',
            'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([
                [
                    'messageId' => $messageId,
                ],
            ]),
        ], [
            'id' => $testEvent->id->__toString(),
            'name' => $testEvent->name,
            'timestamp' => $testEvent->timestamp->toIso8601String(),
        ]);

        // When: Worker consumes message (handler will throw)
        try {
            $this->consumeFromInbox(limit: 1);
        } catch (\Exception $e) {
            // Expected - worker will throw
        }

        // Check actual behavior - does dedup entry exist or not?
        $connection = $this->getContainer()
            ->get('doctrine.dbal.default_connection');
        $messageIdHex = strtoupper(str_replace('-', '', $messageId));
        $count = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM message_broker_deduplication WHERE HEX(message_id) = ?',
            [$messageIdHex]
        );

        // Report actual behavior
        if ($count === 0) {
            $this->markTestIncomplete(
                'Transaction rollback IS working! Dedup entry was rolled back when handler threw. '.
                'This means doctrine_transaction middleware IS active.'
            );
        } else {
            $this->markTestIncomplete(
                'Transaction rollback NOT working. Dedup entry exists even though handler threw. '.
                "Count: {$count}. This means doctrine_transaction middleware is NOT active or not working properly."
            );
        }
    }
}
