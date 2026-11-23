<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp\Tests\Unit;

use Freyr\MessageBroker\Transport\Amqp\AmqpDeliveryControl;
use Freyr\MessageBroker\Transport\Amqp\WaitQueuePublisher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** The per-delivery control against a recording channel. */
final class AmqpDeliveryControlTest extends TestCase
{
    use RecordsChannel;

    protected function setUp(): void
    {
        $this->setUpChannel();
    }

    private function control(\PhpAmqpLib\Message\AMQPMessage $message): AmqpDeliveryControl
    {
        return new AmqpDeliveryControl($message, new WaitQueuePublisher($this->channel, self::QUEUE, 9));
    }

    public function testAckAcksTheDeliveryTag(): void
    {
        $this->control($this->delivery(5))->ack();

        self::assertSame([5], $this->acked);
    }

    public function testRetrySchedulesTheRepublishAndDoesNotAck(): void
    {
        $this->control($this->delivery(4, 'm-4'))->retry(100, 2);

        self::assertCount(1, $this->published);
        self::assertSame('orders.wait.100', $this->published[0]['routingKey']);
        self::assertSame(2, $this->published[0]['message']->get('application_headers')->getNativeData()['x-attempt']);
        self::assertSame([9], $this->pendingAckTimeouts);
        self::assertSame([], $this->acked, 'retry() only schedules; the processor acks afterwards');
    }

    public function testANackedRetryThrowsAndLeavesTheDeliveryUnacked(): void
    {
        $this->nackOnConfirm = true;

        try {
            $this->control($this->delivery(1))->retry(100, 2);
            self::fail('a nacked republish must throw');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('orders.wait.100', $e->getMessage());
        }

        self::assertSame([], $this->acked);
    }
}
