<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\Time\EpochMillis;
use RuntimeException;

final class DeadLetterStoreTest extends FunctionalTestCase
{
    private PdoDeadLetterStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new PdoDeadLetterStore(self::$pdo, static::platform());
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

    public function testStoreAndFindRoundTrip(): void
    {
        $deadLetter = $this->deadLetter(messageId: 'msg-1');

        $this->store->store($deadLetter);
        $found = $this->store->find($deadLetter->id);

        self::assertNotNull($found);
        self::assertSame($deadLetter->id, $found->id);
        self::assertSame('orders_q', $found->source);
        self::assertSame('msg-1', $found->messageId);
        self::assertSame('order.placed', $found->messageName);
        self::assertSame('{"some":"bytes"}', $found->body);
        self::assertSame([
            'x-attempt' => 3,
        ], $found->headers);
        self::assertSame('boom', $found->errorMessage);
        self::assertNull($found->replayedAt);
        self::assertNull($this->store->find('does-not-exist'));
    }

    public function testListFiltersByMessageName(): void
    {
        $this->store->store($this->deadLetter(messageId: 'm-1'));
        $this->store->store($this->deadLetter(messageId: 'm-2', messageName: 'other.event'));

        $filtered = $this->store->list(messageName: 'order.placed');

        self::assertCount(1, $filtered);
        self::assertSame('m-1', $filtered[0]->messageId);
        self::assertCount(2, $this->store->list());
    }

    public function testMarkReplayedKeepsRowForAudit(): void
    {
        $deadLetter = $this->deadLetter(messageId: 'm-1');
        $this->store->store($deadLetter);

        $this->store->markReplayed($deadLetter->id);

        $found = $this->store->find($deadLetter->id);
        self::assertNotNull($found);
        self::assertNotNull($found->replayedAt);
    }

    public function testPurgeDeletesRows(): void
    {
        $this->store->store($this->deadLetter(messageId: 'm-1'));
        $this->store->store($this->deadLetter(messageId: 'm-2'));

        $purged = $this->store->purge();

        self::assertSame(2, $purged);
        self::assertCount(0, $this->store->list());
    }

    public function testCountAndOffsetAndFilteredPurge(): void
    {
        $store = new PdoDeadLetterStore(self::$pdo, static::platform());
        foreach (['order.placed', 'order.placed', 'order.cancelled'] as $i => $name) {
            $store->store(DeadLetter::fromFailure(
                source: 'orders_q',
                messageId: "m-{$i}",
                messageName: $name,
                body: '{}',
                headers: [],
                error: new RuntimeException('boom'),
                attempts: 1,
            ));
        }

        self::assertSame(3, $store->count());
        self::assertSame(2, $store->count(messageName: 'order.placed'));
        self::assertCount(1, $store->list(limit: 1, offset: 0));
        self::assertCount(1, $store->list(limit: 1, offset: 2));

        // Filtered purge removes only the matching rows.
        self::assertSame(2, $store->purge(messageName: 'order.placed'));
        self::assertSame(1, $store->count());
    }

    public function testListAndCountFilterByReplayState(): void
    {
        $replayed = $this->deadLetter(messageId: 'm-1');
        $this->store->store($replayed);
        $this->store->store($this->deadLetter(messageId: 'm-2'));
        $this->store->store($this->deadLetter(messageId: 'm-3'));
        $this->store->markReplayed($replayed->id);

        self::assertSame(3, $this->store->count(), 'no filter sees both states');
        self::assertSame(2, $this->store->count(replayed: false));
        self::assertSame(1, $this->store->count(replayed: true));

        $pending = $this->store->list(replayed: false);
        self::assertCount(2, $pending);
        foreach ($pending as $deadLetter) {
            self::assertNull($deadLetter->replayedAt);
        }

        $done = $this->store->list(replayed: true);
        self::assertCount(1, $done);
        self::assertSame($replayed->id, $done[0]->id);
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
