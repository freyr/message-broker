<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterSink;
use Freyr\MessageBroker\Time\EpochMillis;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Contract tests of the {@see DeadLetterSink} port. An adapter extends this
 * class, returns its sink from createSink() and reads the destination back in
 * storedDeadLetters(), and inherits the tests.
 *
 * Rules for the adapter:
 *  - createSink() returns a sink whose destination is EMPTY at the start of
 *    each test.
 *  - storedDeadLetters() returns each dead letter of the destination, in any
 *    order.
 *
 * The tests use texts below the cuts that a sink may make (1024 bytes of
 * error message, 16384 bytes of error trace) and a new pair of message id and
 * source for each dead letter. They state no rule on two failures of one
 * message and do not test the idempotent rule by id, because a queue sink
 * cannot see its earlier messages; DeadLetterStoreContractTestCase tests both
 * rules for each store.
 */
abstract class DeadLetterSinkContractTestCase extends TestCase
{
    private ?DeadLetterSink $sink = null;

    /** A sink whose destination is empty at the start of the test. */
    abstract protected function createSink(): DeadLetterSink;

    /**
     * Each dead letter of the destination, in any order.
     *
     * @return list<DeadLetter>
     */
    abstract protected function storedDeadLetters(): array;

    public function testAStoredDeadLetterIsReadableWithEachFieldEqual(): void
    {
        $failedAt = intdiv(EpochMillis::now(), 1000) * 1000 - 60_000 + 123;
        $deadLetter = new DeadLetter(
            id: self::newId(),
            source: 'orders_q',
            messageId: 'msg-'.self::newId(),
            messageName: 'order.placed',
            body: '{"metadata":{"message_name":"order.placed"},"payload":{"zażółć":1}}',
            headers: [
                'x-attempt' => 3,
                'x-flag' => true,
                'x-custom' => 'zażółć',
                'x-nested' => [
                    'k' => 'v',
                    'n' => 7,
                ],
            ],
            errorClass: 'RuntimeException',
            errorMessage: "handler failed <- root cause\nzażółć",
            errorTrace: "#0 file.php(1): fail()\n#1 {main}",
            attempts: 7,
            failedAt: $failedAt,
            replayable: true,
        );

        $this->sink()->store($deadLetter);

        $found = $this->found($deadLetter->id);
        self::assertSame($deadLetter->id, $found->id);
        self::assertSame('orders_q', $found->source);
        self::assertSame($deadLetter->messageId, $found->messageId);
        self::assertSame('order.placed', $found->messageName);
        self::assertSame($deadLetter->body, $found->body, 'the body is bytes: no re-encoding');
        self::assertSame(self::sortKeys($deadLetter->headers), self::sortKeys($found->headers));
        self::assertSame('RuntimeException', $found->errorClass);
        self::assertSame($deadLetter->errorMessage, $found->errorMessage);
        self::assertSame($deadLetter->errorTrace, $found->errorTrace);
        self::assertSame(7, $found->attempts);
        self::assertSame($failedAt, $found->failedAt, 'failed_at keeps its milliseconds');
        self::assertTrue($found->replayable);
    }

    public function testARawDeadLetterKeepsItsBytesAndItsMark(): void
    {
        $body = "\x00\x01raw\xffbytes\xfe";
        $deadLetter = $this->deadLetter(body: $body, replayable: false);

        $this->sink()->store($deadLetter);

        $found = $this->found($deadLetter->id);
        self::assertSame($body, $found->body, 'the body stays the exact bytes');
        self::assertFalse($found->replayable);
    }

    public function testTwoDeadLettersOfDifferentMessagesAreBothReadable(): void
    {
        $first = $this->deadLetter(source: 'orders_q');
        $second = $this->deadLetter(source: 'payments_q');

        $this->sink()->store($first);
        $this->sink()->store($second);

        $stored = $this->storedDeadLetters();
        self::assertCount(2, $stored);
        self::assertSame($first->messageId, $this->found($first->id)->messageId);
        self::assertSame('orders_q', $this->found($first->id)->source);
        self::assertSame($second->messageId, $this->found($second->id)->messageId);
        self::assertSame('payments_q', $this->found($second->id)->source);
    }

    final protected function sink(): DeadLetterSink
    {
        return $this->sink ??= $this->createSink();
    }

    private function found(string $id): DeadLetter
    {
        foreach ($this->storedDeadLetters() as $deadLetter) {
            if ($deadLetter->id === $id) {
                return $deadLetter;
            }
        }

        self::fail("no stored dead letter with the id {$id}");
    }

    private function deadLetter(
        string $source = 'orders_q',
        string $body = '{"some":"bytes"}',
        bool $replayable = true,
    ): DeadLetter {
        return new DeadLetter(
            id: self::newId(),
            source: $source,
            messageId: 'msg-'.self::newId(),
            messageName: 'order.placed',
            body: $body,
            headers: [],
            errorClass: 'RuntimeException',
            errorMessage: 'boom',
            errorTrace: '#0 trace-line',
            attempts: 3,
            failedAt: EpochMillis::now() - 10_000,
            replayable: $replayable,
        );
    }

    private static function newId(): string
    {
        return Uuid::v7()->toString();
    }

    /**
     * A destination may keep the keys of a header table in another order: compare the contents only.
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private static function sortKeys(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
