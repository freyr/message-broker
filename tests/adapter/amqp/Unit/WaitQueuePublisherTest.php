<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Amqp\Tests\Unit;

use Freyr\MessageBroker\Adapter\Amqp\WaitQueuePublisher;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** The wait queue mechanics against a recording channel. */
final class WaitQueuePublisherTest extends TestCase
{
    use RecordsChannel;

    protected function setUp(): void
    {
        $this->setUpChannel();
    }

    private function publisher(int $confirmTimeoutSec = 9): WaitQueuePublisher
    {
        return new WaitQueuePublisher($this->channel, self::QUEUE, $confirmTimeoutSec);
    }

    public function testRetryRepublishesToAConfirmedWaitQueueWithTheNextAttempt(): void
    {
        $message = $this->delivery(4, 'm-4', [
            'x-custom' => 'kept',
        ]);

        $this->publisher(confirmTimeoutSec: 9)->scheduleRetry($message, 100, 2);

        self::assertSame(1, $this->confirmSelects);
        self::assertSame(1, $this->nackHandlers);

        self::assertCount(1, $this->declared);
        $declare = $this->declared[0];
        self::assertSame('orders.wait.100', $declare[0]);
        self::assertSame(false, $declare[1], 'passive');
        self::assertSame(true, $declare[2], 'durable');
        self::assertSame(false, $declare[3], 'exclusive');
        self::assertSame(false, $declare[4], 'auto-delete');
        self::assertSame(false, $declare[5], 'nowait');
        self::assertInstanceOf(AMQPTable::class, $declare[6]);
        self::assertSame([
            'x-message-ttl' => 100,
            'x-dead-letter-exchange' => '',
            'x-dead-letter-routing-key' => self::QUEUE,
        ], $declare[6]->getNativeData());

        self::assertCount(1, $this->published);
        $publish = $this->published[0];
        self::assertSame('', $publish['exchange']);
        self::assertSame('orders.wait.100', $publish['routingKey']);
        self::assertSame('{"order_id":"o-1"}', $publish['message']->getBody());
        $headers = $publish['message']->get('application_headers')->getNativeData();
        self::assertSame(2, $headers['x-attempt']);
        self::assertSame('kept', $headers['x-custom']);
        self::assertSame('m-4', $headers['x-message-id']);

        self::assertSame([9], $this->pendingAckTimeouts, 'the republish is confirmed before it returns');
        self::assertSame([], $this->acked, 'the publisher never acks');
    }

    public function testTheNextAttemptReplacesTheAttemptHeaderOfTheMessage(): void
    {
        $this->publisher()->scheduleRetry($this->delivery(1, 'm-1', [
            'x-attempt' => 2,
        ]), 100, 3);

        self::assertSame(3, $this->published[0]['message']->get('application_headers')->getNativeData()['x-attempt']);
    }

    public function testMessageWithoutApplicationHeadersGetsOnlyTheAttemptHeader(): void
    {
        $message = new \PhpAmqpLib\Message\AMQPMessage('body');

        $this->publisher()->scheduleRetry($message, 100, 2);

        self::assertSame([
            'x-attempt' => 2,
        ], $this->published[0]['message']->get('application_headers')->getNativeData());
    }

    public function testConfirmModeAndWaitQueueAreSetUpOncePerDelay(): void
    {
        $publisher = $this->publisher();

        $publisher->scheduleRetry($this->delivery(1, 'm-1'), 100, 2);
        $publisher->scheduleRetry($this->delivery(2, 'm-2'), 100, 2);

        self::assertSame(1, $this->confirmSelects);
        self::assertSame(1, $this->nackHandlers);
        self::assertCount(1, $this->declared, 'the wait queue of one delay is declared once');
        self::assertCount(2, $this->published);
    }

    public function testTheConfirmTimeoutDefaultsToFiveSeconds(): void
    {
        (new WaitQueuePublisher($this->channel, self::QUEUE))->scheduleRetry($this->delivery(), 100, 2);

        self::assertSame([5], $this->pendingAckTimeouts);
    }

    public function testEachDelayGetsItsOwnWaitQueue(): void
    {
        $publisher = $this->publisher();

        $publisher->scheduleRetry($this->delivery(1, 'm-1'), 100, 2);
        $publisher->scheduleRetry($this->delivery(2, 'm-2'), 250, 2);

        self::assertCount(2, $this->declared);
        self::assertSame('orders.wait.250', $this->declared[1][0]);
        self::assertSame('orders.wait.250', $this->published[1]['routingKey']);
    }

    public function testBrokerNackOfTheRepublishThrows(): void
    {
        $this->nackOnConfirm = true;

        try {
            $this->publisher()->scheduleRetry($this->delivery(1, 'm-1'), 100, 2);
            self::fail('a nacked republish must throw');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('orders.wait.100', $e->getMessage());
        }
    }

    public function testANackOfAnEarlierRepublishDoesNotFailALaterConfirmedOne(): void
    {
        $publisher = $this->publisher();
        $this->nackOnConfirm = true;
        try {
            $publisher->scheduleRetry($this->delivery(1, 'm-1'), 100, 2);
        } catch (RuntimeException) {
        }
        $this->nackOnConfirm = false;

        $publisher->scheduleRetry($this->delivery(2, 'm-2'), 100, 2);

        self::assertCount(2, $this->published);
    }
}
