<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeadLetterStore;
use Freyr\MessageBroker\Time\EpochMillis;
use RuntimeException;

final class DeadLetterStoreTest extends FunctionalTestCase
{
    private PostgreSqlDeadLetterStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new PostgreSqlDeadLetterStore(self::$pdo);
    }

    public function testFromFailureCapturesErrorChainAndTimestamps(): void
    {
        $cause = new RuntimeException('root cause');
        $error = new RuntimeException('handler failed', previous: $cause);

        $deadLetter = DeadLetter::fromFailure(
            source: 'orders_q',
            messageId: 'msg-1',
            messageName: 'order.placed',
            body: '{"some":"bytes"}',
            headers: [
                'x-attempt' => 5,
            ],
            error: $error,
            attempts: 5,
        );

        self::assertNotSame('', $deadLetter->id);
        self::assertSame(RuntimeException::class, $deadLetter->errorClass);
        self::assertStringContainsString('handler failed', $deadLetter->errorMessage);
        self::assertStringContainsString('root cause', $deadLetter->errorMessage, 'error chain must be captured');
        self::assertSame(5, $deadLetter->attempts);
        self::assertEqualsWithDelta(EpochMillis::now(), $deadLetter->failedAt, 2_000);
        self::assertNull($deadLetter->replayedAt);
    }

    public function testHeadersNestedJustBelowTheJsonDecodeDepthLimitRoundTrip(): void
    {
        $headers = 'leaf';
        for ($level = 0; $level < 511; ++$level) {
            $headers = [
                'k' => $headers,
            ];
        }
        $deadLetter = DeadLetter::fromFailure(
            source: 'orders_q',
            messageId: 'm-deep',
            messageName: 'order.placed',
            body: '{}',
            headers: $headers,
            error: new RuntimeException('boom'),
            attempts: 1,
        );
        $this->store->store($deadLetter);

        self::assertSame($headers, $this->store->find($deadLetter->id)?->headers);
    }

    public function testHeadersNestedToTheJsonDecodeDepthLimitAreRefusedOnRead(): void
    {
        $deadLetter = $this->deadLetter(messageId: 'm-deeper');
        $this->store->store($deadLetter);
        $json = str_repeat('{"k":', 512).'1'.str_repeat('}', 512);
        self::$pdo->prepare('UPDATE dead_letters SET headers = :headers WHERE id = :id')->execute([
            'headers' => $json,
            'id' => $deadLetter->id,
        ]);

        $this->expectException(\JsonException::class);

        $this->store->find($deadLetter->id);
    }

    /**
     * Two sessions store the same pair, one after the other: the second store
     * updates the open row of the first. Each session opens its own direct
     * connection, so the test does not depend on a pooler. It checks the
     * sequential case only; the concurrent case rests on ON CONFLICT.
     */
    public function testAStoreOnAConnectionAndAStoreOfTheSamePairOnAnotherConnectionGiveOneRow(): void
    {
        $first = $this->deadLetter(messageId: 'm-pair');
        $second = $this->deadLetter(messageId: 'm-pair', messageName: 'order.second');

        (new PostgreSqlDeadLetterStore(self::newConnection()))->store($first);
        (new PostgreSqlDeadLetterStore(self::newConnection()))->store($second);

        self::assertSame(1, self::fetchInt("SELECT COUNT(*) FROM dead_letters WHERE message_id = 'm-pair'"));
        self::assertSame('order.second', $this->store->find($first->id)?->messageName);
        self::assertNull($this->store->find($second->id));
    }

    public function testAStoreOfANewPairWithNoIndexFailsWithTheMessageThatTheDocumentsName(): void
    {
        self::$pdo->exec('DROP INDEX uq_dead_letters_open');

        try {
            $this->store->store($this->deadLetter(messageId: 'm-no-index'));
            self::fail('the store names the index in ON CONFLICT');
        } catch (\PDOException $error) {
            self::assertStringContainsString(
                'there is no unique or exclusion constraint matching the ON CONFLICT specification',
                $error->getMessage(),
            );
        } finally {
            self::schemaInstaller()->install(0);
        }
    }

    private function deadLetter(string $messageId, string $messageName = 'order.placed'): DeadLetter
    {
        return DeadLetter::fromFailure(
            source: 'orders_q',
            messageId: $messageId,
            messageName: $messageName,
            body: '{"some":"bytes"}',
            headers: [
                'x-attempt' => 3,
            ],
            error: new RuntimeException('boom'),
            attempts: 3,
        );
    }
}
