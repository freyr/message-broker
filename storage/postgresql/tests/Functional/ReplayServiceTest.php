<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use Closure;
use Freyr\MessageBroker\DeadLetter\AlreadyReplayed;
use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\ReplayService;
use Freyr\MessageBroker\Message;
use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use Freyr\MessageBroker\Storage\PostgreSql\PdoTransaction;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeadLetterStore;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxStore;
use Freyr\MessageBroker\Testing\OrderPlaced;
use Freyr\MessageBroker\Testing\RecordingEvents;
use PDO;
use PDOException;
use RuntimeException;

final class ReplayServiceTest extends FunctionalTestCase
{
    private PostgreSqlDeadLetterStore $deadLetters;
    private ReplayService $replay;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deadLetters = new PostgreSqlDeadLetterStore(self::$pdo);
        $this->replay = new ReplayService($this->deadLetters, new PostgreSqlOutboxStore(
            self::$pdo,
        ), new PdoTransaction(self::$pdo), new JsonWireFormat());
    }

    public function testReplayReenqueuesIntoOutboxUnderGivenLane(): void
    {
        $message = OrderPlaced::create('o-1', 4999);
        $deadLetter = DeadLetter::fromFailure(
            source: 'orders_q',
            messageId: $message->id,
            messageName: $message->name,
            body: (string) json_encode(self::dlqDocument($message)),
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
        self::assertNull($row['published_at'], 'the replayed row waits for the relay');

        self::assertSame(
            $message->payload(),
            json_decode(self::bodyOf($row['body']), true),
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
            body: (string) json_encode(self::dlqDocument($message)),
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

    public function testReplayFiresReplayedEvent(): void
    {
        $events = new RecordingEvents();
        $replay = new ReplayService(
            $this->deadLetters,
            new PostgreSqlOutboxStore(self::$pdo),
            new PdoTransaction(self::$pdo),
            new JsonWireFormat(),
            $events,
        );
        $message = OrderPlaced::create('o-1', 4999);
        $deadLetter = DeadLetter::fromFailure(
            source: 'orders_q',
            messageId: $message->id,
            messageName: $message->name,
            body: (string) json_encode(self::dlqDocument($message)),
            headers: [],
            error: new RuntimeException('boom'),
            attempts: 5,
        );
        $this->deadLetters->store($deadLetter);

        $replay->replay($deadLetter->id, lane: 'orders');

        self::assertContains(BrokerEvents::REPLAYED, $events->names());
    }

    public function testTheOutboxRowAndTheMarkCommitTogether(): void
    {
        $deadLetter = $this->storedDeadLetter();

        $this->replay->replay($deadLetter->id, lane: 'orders');

        $observer = self::newConnection();
        self::assertSame(1, self::countOn($observer, 'SELECT COUNT(*) FROM outbox_messages'));
        self::assertSame(1, self::countOn($observer, 'SELECT COUNT(*) FROM dead_letters WHERE replayed_at IS NOT NULL'));
    }

    public function testAnInsertThatThrowsLeavesNoOutboxRowAndNoMark(): void
    {
        $deadLetter = $this->storedDeadLetter();
        $failure = new RuntimeException('after the insert');
        $replay = $this->replayService(self::$pdo, self::outboxThatThenRuns(self::$pdo, static function () use ($failure): void {
            throw $failure;
        }));

        try {
            $replay->replay($deadLetter->id);
            self::fail('Expected the failure of the outbox store');
        } catch (RuntimeException $error) {
            self::assertSame($failure, $error);
        }

        $observer = self::newConnection();
        self::assertSame(0, self::countOn($observer, 'SELECT COUNT(*) FROM outbox_messages'));
        self::assertSame(1, self::countOn($observer, 'SELECT COUNT(*) FROM dead_letters WHERE replayed_at IS NULL'));
    }

    public function testAConnectionThatEndsBeforeTheCommitLeavesNoOutboxRowAndNoMark(): void
    {
        $deadLetter = $this->storedDeadLetter();
        $victim = self::newConnection();
        $replay = $this->replayService($victim, self::outboxThatThenRuns($victim, static function () use ($victim): void {
            // Read inside the transaction: behind a pooler in transaction mode the
            // server connection is fixed only while the transaction is open.
            $pid = self::countOn($victim, 'SELECT pg_backend_pid()');
            self::$pdo->query("SELECT pg_terminate_backend({$pid})");
        }));

        try {
            $replay->replay($deadLetter->id);
            self::fail('Expected the commit on the ended connection to fail');
        } catch (PDOException) {
            // the server ended the connection: the replay reports a failure
        }

        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'));
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM dead_letters WHERE replayed_at IS NULL'));
    }

    public function testASecondReplayIsAlreadyReplayedAndLeavesOneOutboxRow(): void
    {
        $deadLetter = $this->storedDeadLetter();
        $this->replay->replay($deadLetter->id);

        try {
            $this->replay->replay($deadLetter->id);
            self::fail('Expected AlreadyReplayed');
        } catch (AlreadyReplayed $error) {
            self::assertSame("Dead letter '{$deadLetter->id}' is already replayed", $error->getMessage());
        }

        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'));
    }

    public function testASecondSessionWaitsForTheMarkAndGetsFalseAfterACommit(): void
    {
        $deadLetter = $this->storedDeadLetter();
        $sessionA = self::newConnection();
        $sessionB = self::newConnection();
        $sessionA->beginTransaction();
        self::assertTrue((new PostgreSqlDeadLetterStore($sessionA))->markReplayed($deadLetter->id));

        self::assertLockTimeout($sessionB, $deadLetter->id);
        $sessionA->commit();

        self::assertFalse(
            (new PostgreSqlDeadLetterStore($sessionB))->markReplayed($deadLetter->id),
            'a mark after the commit of the other session finds the row replayed',
        );
    }

    public function testASecondSessionWaitsForTheMarkAndGetsTrueAfterARollback(): void
    {
        $deadLetter = $this->storedDeadLetter();
        $sessionA = self::newConnection();
        $sessionB = self::newConnection();
        $sessionA->beginTransaction();
        self::assertTrue((new PostgreSqlDeadLetterStore($sessionA))->markReplayed($deadLetter->id));

        self::assertLockTimeout($sessionB, $deadLetter->id);
        $sessionA->rollBack();

        self::assertTrue((new PostgreSqlDeadLetterStore($sessionB))->markReplayed($deadLetter->id));
    }

    public function testTheHeadersColumnHoldsTheReplayTarget(): void
    {
        $deadLetter = $this->storedDeadLetter(source: 'payments_q', headers: [
            'x-replay-target' => 'old_q',
            'x-delivery-count' => 9,
        ]);

        $this->replay->replay($deadLetter->id);

        $statement = self::$pdo->query('SELECT headers FROM outbox_messages');
        self::assertNotFalse($statement);
        self::assertSame([
            'x-replay-target' => 'payments_q',
        ], json_decode((string) $statement->fetchColumn(), true));
    }

    public function testTwoConnectionsWithTheTransactionOnTheDeadLetterConnectionKeepAnOutboxRowAndNoMarkAfterAStop(): void
    {
        $deadLetter = $this->storedDeadLetter();
        $deadLetterConnection = self::newConnection();
        $outboxConnection = self::newConnection();
        $stop = true;
        $replay = $this->replayService($deadLetterConnection, self::outboxThatThenRuns(
            $outboxConnection,
            static function () use (&$stop): void {
                if ($stop) {
                    $stop = false;

                    throw new RuntimeException('stop between the insert and the commit');
                }
            },
        ));

        try {
            $replay->replay($deadLetter->id);
            self::fail('Expected the stop');
        } catch (RuntimeException $error) {
            self::assertSame('stop between the insert and the commit', $error->getMessage());
        }

        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'), 'the insert committed on its own connection');
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM dead_letters WHERE replayed_at IS NULL'), 'the mark rolled back');

        $replay->replay($deadLetter->id);

        self::assertSame(
            2,
            self::fetchInt("SELECT COUNT(*) FROM outbox_messages WHERE id = '{$deadLetter->messageId}'"),
            'the safe direction: a second copy of the message, not a lost replay',
        );
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM dead_letters WHERE replayed_at IS NULL'));
    }

    /**
     * The mark of the dead letter fails on the lock of an open mark of the
     * other session: the statement waits, and the lock timeout ends the wait.
     */
    private static function assertLockTimeout(PDO $session, string $deadLetterId): void
    {
        $session->beginTransaction();
        $session->exec("SET LOCAL lock_timeout = '100ms'");
        try {
            (new PostgreSqlDeadLetterStore($session))->markReplayed($deadLetterId);
            self::fail('Expected a lock timeout while the other session holds the row');
        } catch (PDOException $error) {
            self::assertSame('55P03', $error->getCode(), 'lock_not_available');
        } finally {
            $session->rollBack();
        }
    }

    private function replayService(PDO $deadLetterConnection, OutboxStore $outbox): ReplayService
    {
        return new ReplayService(
            new PostgreSqlDeadLetterStore($deadLetterConnection),
            $outbox,
            new PdoTransaction($deadLetterConnection),
            new JsonWireFormat(),
        );
    }

    /** A PostgreSQL outbox store on the connection that runs $afterInsert after each insert. */
    private static function outboxThatThenRuns(PDO $connection, Closure $afterInsert): OutboxStore
    {
        return new class(new PostgreSqlOutboxStore($connection), $afterInsert) implements OutboxStore {
            public function __construct(
                private readonly OutboxStore $inner,
                private readonly Closure $afterInsert,
            ) {}

            public function insert(OutboxRecord $record): void
            {
                $this->inner->insert($record);
                ($this->afterInsert)();
            }

            public function drainClaimed(string $lane, int $limit, callable $publish): int
            {
                return $this->inner->drainClaimed($lane, $limit, $publish);
            }

            public function abandon(string $id): int
            {
                return $this->inner->abandon($id);
            }

            public function failing(string $lane, int $minAttempts, int $limit): array
            {
                return $this->inner->failing($lane, $minAttempts, $limit);
            }
        };
    }

    /** @param array<string, mixed> $headers */
    private function storedDeadLetter(string $source = 'orders_q', array $headers = []): DeadLetter
    {
        $message = OrderPlaced::create('o-1', 4999);
        $deadLetter = DeadLetter::fromFailure(
            source: $source,
            messageId: $message->id,
            messageName: $message->name,
            body: (string) json_encode(self::dlqDocument($message)),
            headers: $headers,
            error: new RuntimeException('handler exploded'),
            attempts: 5,
        );
        $this->deadLetters->store($deadLetter);

        return $deadLetter;
    }

    private static function countOn(PDO $connection, string $sql): int
    {
        $statement = $connection->query($sql);
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }

    /**
     * The replayable document a consumer stores in the DLQ.
     *
     * @return array{metadata: array<string, mixed>, payload: array<string, mixed>}
     */
    private static function dlqDocument(Message $message): array
    {
        return [
            'metadata' => [
                'message_name' => $message->name,
                'message_id' => $message->id,
                'created_at' => $message->createdAt,
            ],
            'payload' => $message->payload(),
        ];
    }
}
