<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\DeadLetter\ReplayService;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Serializer\MetadataHeader;
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
        $platform = static::platform();
        $this->deadLetters = new PdoDeadLetterStore(self::$pdo, $platform);
        $this->replay = new ReplayService($this->deadLetters, new OutboxStore(
            self::$pdo,
            $platform
        ), new JsonWireFormat());
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

        $metadata = json_decode((string) $row['metadata'], true);
        self::assertSame('order.placed', $metadata['message_name']);
        self::assertSame($message->id, $metadata['message_id']);

        self::assertSame(
            $message->wire()['payload'],
            json_decode(static::platform()->readBody($row['body']), true),
            'payload is re-encoded into the outbox body',
        );

        $replayed = $this->deadLetters->find($deadLetter->id);
        self::assertNotNull($replayed);
        self::assertNotNull($replayed->replayedAt, 'dead letter is marked replayed, kept for audit');
    }

    public function testReplayStripsRetryAndMetadataHeadersButKeepsApplicationHeaders(): void
    {
        // A message that died after 2 attempts carries stale x-attempt and
        // x-* serializer metadata in its headers.  Replaying it must restore
        // the full retry budget (by dropping x-attempt) and let the lane's
        // serializer re-derive x-message-id / x-message-name / x-created-at
        // fresh.  Application headers (correlation_id, etc.) must survive.
        $message = OrderPlaced::create('o-replay-2', 999);
        $deadLetter = DeadLetter::fromFailure(
            source: 'orders_q',
            messageId: $message->id,
            messageName: $message->name,
            body: (string) json_encode($message->wire()),
            headers: [
                'x-attempt' => 2,
                MetadataHeader::MESSAGE_ID => 'stale-id',
                MetadataHeader::MESSAGE_NAME => 'stale-name',
                MetadataHeader::CREATED_AT => 1,
                'correlation_id' => 'corr-replay',
            ],
            error: new RuntimeException('died on attempt 2'),
            attempts: 2,
        );
        $this->deadLetters->store($deadLetter);

        $this->replay->replay($deadLetter->id, lane: 'orders');

        $row = self::$pdo->query(
            "SELECT headers FROM outbox_messages WHERE id = '{$message->id}'",
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        /** @var array<string, mixed> $headers */
        $headers = json_decode((string) $row['headers'], true);

        self::assertArrayHasKey('correlation_id', $headers, 'application headers must survive replay');
        self::assertSame('corr-replay', $headers['correlation_id']);

        self::assertArrayNotHasKey('x-attempt', $headers, 'x-attempt must be stripped so the retry budget resets');
        self::assertArrayNotHasKey(MetadataHeader::MESSAGE_ID, $headers, 'stale envelope headers must be stripped');
        self::assertArrayNotHasKey(MetadataHeader::MESSAGE_NAME, $headers, 'stale envelope headers must be stripped');
        self::assertArrayNotHasKey(MetadataHeader::CREATED_AT, $headers, 'stale envelope headers must be stripped');
    }

    public function testReplayUnknownIdThrows(): void
    {
        $this->expectException(RuntimeException::class);

        $this->replay->replay('does-not-exist', lane: 'orders');
    }
}
