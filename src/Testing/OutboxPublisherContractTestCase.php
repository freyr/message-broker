<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\Outbox\OutboxPublisher;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Time\EpochMillis;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Contract tests of the {@see OutboxPublisher} port. An adapter extends this
 * class, returns its publisher from createPublisher(), and inherits the tests.
 *
 * The tests cover the part of the contract that a test can check from outside:
 * a batch that the transport accepts returns no refused id, maxBatchSize() is
 * positive, and the publisher accepts a batch of the size that it announces
 * (up to 25 rows in the tests). The refusal of a row and the failure of the
 * connection depend on the transport: test them in the adapter.
 *
 * Rules for the adapter:
 *  - createPublisher() returns a publisher that is connected to a transport that
 *    accepts every message (a reachable broker, a queue that exists).
 *  - Implement assertDelivered() to prove the arrival on the transport. The
 *    default does nothing, so a publisher without a readable transport still
 *    passes the other tests.
 */
abstract class OutboxPublisherContractTestCase extends TestCase
{
    private const int LARGEST_TESTED_BATCH = 25;

    private ?OutboxPublisher $publisher = null;

    abstract protected function createPublisher(): OutboxPublisher;

    /**
     * Assert that the transport received these records, in any order and exactly
     * once each. Called after each successful publishBatch().
     *
     * @param list<OutboxRecord> $records
     */
    protected function assertDelivered(array $records): void
    {
        // Default: the transport cannot be read.
    }

    public function testMaxBatchSizeIsPositive(): void
    {
        self::assertGreaterThan(0, $this->publisher()->maxBatchSize());
    }

    public function testABatchOfOneRowIsAccepted(): void
    {
        $batch = self::batch(1);

        self::assertSame([], $this->publisher()->publishBatch($batch), 'a retried row goes alone');
        $this->assertDelivered($batch);
    }

    public function testABatchThatTheTransportAcceptsReturnsNoRefusedId(): void
    {
        $batch = self::batch(min($this->publisher()->maxBatchSize(), 3));

        self::assertSame([], $this->publisher()->publishBatch($batch));
        $this->assertDelivered($batch);
    }

    public function testABatchOfTheLargestAnnouncedSizeIsAccepted(): void
    {
        $batch = self::batch(min($this->publisher()->maxBatchSize(), self::LARGEST_TESTED_BATCH));

        self::assertSame([], $this->publisher()->publishBatch($batch));
        $this->assertDelivered($batch);
    }

    public function testThePublisherServesSeveralBatchesInARow(): void
    {
        $first = self::batch(2);
        $second = self::batch(1);

        self::assertSame([], $this->publisher()->publishBatch($first));
        self::assertSame([], $this->publisher()->publishBatch($second));

        $this->assertDelivered([...$first, ...$second]);
    }

    final protected function publisher(): OutboxPublisher
    {
        return $this->publisher ??= $this->createPublisher();
    }

    /**
     * @param positive-int $size
     *
     * @return non-empty-list<OutboxRecord>
     */
    private static function batch(int $size): array
    {
        $batch = [];
        for ($i = 0; $i < $size; ++$i) {
            $batch[] = new OutboxRecord(
                id: Uuid::v7()->toString(),
                lane: 'contract',
                messageName: 'order.placed',
                body: '{"order_id":"o-'.$i.'"}',
                headers: [
                    'correlation_id' => 'c-'.$i,
                ],
                createdAt: EpochMillis::now() - 1_000,
            );
        }

        return $batch;
    }
}
