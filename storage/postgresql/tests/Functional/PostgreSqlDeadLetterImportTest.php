<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use DateTime;
use Freyr\MessageBroker\Consumer\Delivery;
use Freyr\MessageBroker\DeadLetter\DeadLetterBuilder;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\DeadLetter\DeadLetterImporter;
use Freyr\MessageBroker\DeadLetter\TransportMove;
use Freyr\MessageBroker\Serializer\JsonDeserializer;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeadLetterStore;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The import of a message of a dead letter queue into PostgreSQL: each content
 * cause that the invariant of DeadLetter covers (invalid UTF-8, a NUL
 * character, a name above 255 characters), also inside x-death, gives a stored
 * row and no failed store.
 */
final class PostgreSqlDeadLetterImportTest extends FunctionalTestCase
{
    private PostgreSqlDeadLetterStore $store;

    private DeadLetterImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new PostgreSqlDeadLetterStore(self::$pdo);
        $this->importer = new DeadLetterImporter($this->store, new JsonDeserializer(), new DeadLetterBuilder());
    }

    /**
     * @param array<string, mixed> $extraHeaders
     * @param array<string, mixed> $death
     */
    private static function delivery(
        string $messageId = 'm-1',
        string $messageName = 'order.placed',
        array $extraHeaders = [],
        array $death = [],
        string $body = '{"order_id":"o-1"}',
    ): Delivery {
        $headers = MetadataHeader::envelope($messageId, $messageName, 1_765_476_000_123) + $extraHeaders + [
            'x-death' => [$death + [
                'count' => 1,
                'reason' => 'delivery_limit',
                'queue' => 'orders_q',
                'time' => new DateTime('@1795219200'),
                'exchange' => '',
                'routing-keys' => ['orders_q'],
            ]],
        ];

        return new Delivery($body, $headers, 2, 'orders_q.dlq', null, 3);
    }

    /** @return iterable<string, array{Delivery, ?TransportMove}> */
    public static function contentCauses(): iterable
    {
        $move = new TransportMove('orders_q', 'delivery_limit', 1, 1_795_219_200_000);

        yield 'invalid UTF-8 in the message id' => [self::delivery(messageId: "m-\xFF"), $move];
        yield 'a NUL character in the message name' => [self::delivery(messageName: "order.\x00placed"), $move];
        yield 'a message id above 255 characters' => [self::delivery(messageId: str_repeat('m', 300)), $move];
        yield 'invalid UTF-8 in a header value' => [self::delivery(extraHeaders: [
            'x-note' => "caf\xE9",
        ]), $move];
        yield 'a NUL character in a header key' => [self::delivery(extraHeaders: [
            "x-\x00key" => 'v',
        ]), $move];
        yield 'invalid UTF-8 inside x-death' => [self::delivery(death: [
            'queue' => "orders_q\xFF",
        ]), $move];
        yield 'a NUL character inside x-death' => [self::delivery(death: [
            'reason' => "delivery\x00limit",
            "x-\x00key" => "v\x00",
        ]), $move];
        yield 'invalid UTF-8 and a NUL character in the source queue of the move' => [
            self::delivery(),
            new TransportMove("orders\x00\xFF".str_repeat('q', 300), "reason\xFF", 1),
        ];
        yield 'a raw body with a NUL character and invalid UTF-8' => [self::delivery(body: "\x00\xFF{{{"), $move];
        yield 'no move and invalid UTF-8 in the message id' => [self::delivery(messageId: "m-\xFF"), null];
    }

    #[DataProvider('contentCauses')]
    public function testAMessageWithAContentCauseIsImportedAndStored(Delivery $delivery, ?TransportMove $move): void
    {
        $imported = $this->importer->import($delivery, $move);

        $rows = $this->store->list(new DeadLetterFilter());
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame($imported->id, $row->id);
        self::assertSame($imported->source, $row->source);
        self::assertSame($imported->messageId, $row->messageId);
        self::assertSame($imported->messageName, $row->messageName);
        self::assertSame($imported->body, $row->body, 'the body stays the exact bytes');
        self::assertSame($imported->errorClass, $row->errorClass);
        self::assertSame($imported->errorMessage, $row->errorMessage);
        self::assertSame($imported->attempts, $row->attempts);
        self::assertSame($imported->failedAt, $row->failedAt);
        self::assertSame($imported->replayable, $row->replayable);
        self::assertTrue(mb_check_encoding($row->messageId, 'UTF-8'));
        self::assertStringNotContainsString("\0", $row->messageName);
        self::assertEquals(
            json_decode(json_encode($imported->headers, JSON_THROW_ON_ERROR), true),
            $row->headers,
            'the headers, also x-death, are stored (JSONB does not keep the key order)',
        );
    }

    public function testASecondImportOfTheSameMovedMessageGivesOneOpenRow(): void
    {
        $move = new TransportMove('orders_q', 'delivery_limit', 1, 1_795_219_200_000);
        $first = $this->importer->import(self::delivery(), $move);

        $this->importer->import(self::delivery(), $move);

        $rows = $this->store->list(new DeadLetterFilter());
        self::assertCount(1, $rows);
        self::assertSame($first->id, $rows[0]->id);
    }
}
