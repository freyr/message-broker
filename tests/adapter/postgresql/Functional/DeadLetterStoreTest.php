<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeadLetterStore;
use Freyr\MessageBroker\DeadLetter\DeadLetter;
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
