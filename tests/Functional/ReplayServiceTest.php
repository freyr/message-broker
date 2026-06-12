<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\DeadLetter\ReplayService;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Storage\MySqlPlatform;
use Freyr\MessageBroker\Tests\Fixtures\OrderPlaced;
use PDO;
use RuntimeException;

final class ReplayServiceTest extends FunctionalTestCase
{
    private PdoDeadLetterStore $deadLetters;
    private ReplayService $replay;

    protected function setUp(): void
    {
        parent::setUp();
        $platform = new MySqlPlatform();
        $this->deadLetters = new PdoDeadLetterStore(self::$pdo, $platform);
        $this->replay = new ReplayService($this->deadLetters, new OutboxStore(self::$pdo, $platform));
    }

    public function testReplayReenqueuesIntoOutboxUnderGivenLane(): void
    {
        $message = OrderPlaced::create('o-1', 4999);
        $deadLetter = DeadLetter::fromFailure(
            source: 'orders_q',
            messageId: $message->id,
            messageName: $message->name,
            body: (string) json_encode($message->wire()),
            headers: [
                'correlation_id' => 'c-1',
            ],
            error: new RuntimeException('handler exploded'),
            attempts: 5,
        );
        $this->deadLetters->store($deadLetter);

        $this->replay->replay($deadLetter->id, lane: 'orders');

        $row = self::$pdo->query('SELECT * FROM outbox_messages')->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame($message->id, $row['id'], 'original message id is preserved');
        self::assertSame('orders', $row['lane']);
        self::assertSame('order.placed', $row['message_name']);
        // assertEquals: MySQL JSON columns normalize object key order.
        self::assertEquals(
            json_decode((string) json_encode($message->wire()), true),
            json_decode((string) $row['body'], true),
            'original wire document is preserved',
        );

        $replayed = $this->deadLetters->find($deadLetter->id);
        self::assertNotNull($replayed);
        self::assertNotNull($replayed->replayedAt, 'dead letter is marked replayed, kept for audit');
    }

    public function testReplayUnknownIdThrows(): void
    {
        $this->expectException(RuntimeException::class);

        $this->replay->replay('does-not-exist', lane: 'orders');
    }
}
