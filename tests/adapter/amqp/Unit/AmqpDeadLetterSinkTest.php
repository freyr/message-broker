<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Amqp\Tests\Unit;

use Freyr\MessageBroker\Adapter\Amqp\AmqpDeadLetterConfig;
use Freyr\MessageBroker\Adapter\Amqp\AmqpDeadLetterSink;
use Freyr\MessageBroker\BrokerHeader;
use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterHeader;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** The publish of the AMQP dead letter sink against a recording channel. */
final class AmqpDeadLetterSinkTest extends TestCase
{
    use RecordsChannel;

    protected function setUp(): void
    {
        $this->setUpChannel();
    }

    private function sink(int $confirmTimeoutSec = 7): AmqpDeadLetterSink
    {
        return new AmqpDeadLetterSink($this->channel, new AmqpDeadLetterConfig('dlx', 'orders_q.dlq', $confirmTimeoutSec));
    }

    /** @param array<string, mixed> $headers */
    private static function deadLetter(
        string $id = '0190f7e2-1234-7000-8000-abc123456789',
        array $headers = [
            'x-custom' => 'h',
            'x-nested' => [
                'k' => 'v',
            ],
        ],
    ): DeadLetter {
        return new DeadLetter(
            id: $id,
            source: 'orders_q',
            messageId: 'm-1',
            messageName: 'order.placed',
            body: "raw\xffbody",
            headers: $headers,
            errorClass: 'App\PaymentDeclined',
            errorMessage: 'card expired',
            errorTrace: '#0 trace-line',
            attempts: 5,
            failedAt: 1_795_219_200_123,
            replayable: false,
        );
    }

    /** @return array<string, mixed> */
    private function publishedHeaders(int $index = 0): array
    {
        $headers = $this->published[$index]['message']->get('application_headers');
        self::assertInstanceOf(AMQPTable::class, $headers);

        /** @var array<string, mixed> */
        return $headers->getNativeData();
    }

    public function testThePublishGoesToTheExchangeAndRoutingKeyAsAPersistentMandatoryMessage(): void
    {
        $deadLetter = self::deadLetter();

        $this->sink(confirmTimeoutSec: 7)->store($deadLetter);

        self::assertCount(1, $this->published);
        $publish = $this->published[0];
        self::assertSame('dlx', $publish['exchange']);
        self::assertSame('orders_q.dlq', $publish['routingKey']);
        self::assertTrue($publish['mandatory'], 'an unroutable dead letter must come back');
        self::assertSame("raw\xffbody", $publish['message']->getBody());
        self::assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $publish['message']->get('delivery_mode'));
        self::assertSame('m-1', $publish['message']->get('message_id'));
        self::assertSame([
            'x-custom' => 'h',
            'x-nested' => [
                'k' => 'v',
            ],
            BrokerHeader::DEAD_LETTER => DeadLetterHeader::encode($deadLetter),
        ], $this->publishedHeaders());
        self::assertSame([7], $this->pendingAckReturnTimeouts, 'the confirm and the returns are awaited before store() returns');
        self::assertSame([], $this->pendingAckTimeouts);
    }

    public function testTheHeaderOfTheSinkWinsOverAHeaderOfTheSameName(): void
    {
        $deadLetter = self::deadLetter(headers: [
            BrokerHeader::DEAD_LETTER => 'stale copy',
        ]);

        $this->sink()->store($deadLetter);

        self::assertSame([
            BrokerHeader::DEAD_LETTER => DeadLetterHeader::encode($deadLetter),
        ], $this->publishedHeaders());
    }

    public function testConfirmModeAndTheHandlersAreSetOnce(): void
    {
        $sink = $this->sink();

        $sink->store(self::deadLetter());
        $sink->store(self::deadLetter());

        self::assertSame(1, $this->confirmSelects);
        self::assertSame(1, $this->nackHandlers);
        self::assertSame(1, $this->returnListeners);
        self::assertCount(2, $this->published);
        self::assertSame([7, 7], $this->pendingAckReturnTimeouts);
    }

    public function testANackThrows(): void
    {
        $this->duringConfirmWait = function (): void {
            ($this->nackHandler)($this->published[array_key_last($this->published)]['message']);
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("The broker nacked the dead letter 0190f7e2-1234-7000-8000-abc123456789 to exchange 'dlx', routing key 'orders_q.dlq'");
        $this->sink()->store(self::deadLetter());
    }

    public function testAReturnThrows(): void
    {
        $this->duringConfirmWait = function (): void {
            ($this->returnListener)(312, 'NO_ROUTE', 'dlx', 'orders_q.dlq', new AMQPMessage('returned'));
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("The broker returned the dead letter 0190f7e2-1234-7000-8000-abc123456789 to exchange 'dlx', routing key 'orders_q.dlq': 312 NO_ROUTE");
        $this->sink()->store(self::deadLetter());
    }

    public function testATimeoutThrowsTheErrorOfTheLibrary(): void
    {
        $timeout = new AMQPTimeoutException('confirm timeout');
        $this->duringConfirmWait = static function () use ($timeout): void {
            throw $timeout;
        };

        try {
            $this->sink()->store(self::deadLetter());
            self::fail('a timeout must throw');
        } catch (AMQPTimeoutException $e) {
            self::assertSame($timeout, $e);
        }
    }

    public function testANackOfAnEarlierPublishDoesNotFailALaterOne(): void
    {
        $sink = $this->sink();
        $this->duringConfirmWait = static function (): void {
            throw new AMQPTimeoutException('confirm timeout');
        };
        try {
            $sink->store(self::deadLetter('0190f7e2-1234-7000-8000-000000000001'));
            self::fail('the first publish times out');
        } catch (AMQPTimeoutException) {
        }

        // The confirm of the first publish arrives late, as a nack, during the wait of the second.
        $this->duringConfirmWait = function (): void {
            ($this->nackHandler)($this->published[0]['message']);
        };
        $sink->store(self::deadLetter('0190f7e2-1234-7000-8000-000000000002'));

        self::assertCount(2, $this->published);
    }

    public function testAReturnOfAnEarlierPublishDoesNotStayForALaterOne(): void
    {
        $sink = $this->sink();
        $this->duringConfirmWait = function (): void {
            ($this->returnListener)(312, 'NO_ROUTE', 'dlx', 'orders_q.dlq', new AMQPMessage('returned'));
        };
        try {
            $sink->store(self::deadLetter());
            self::fail('the first publish is returned');
        } catch (RuntimeException) {
        }
        $this->duringConfirmWait = null;

        $sink->store(self::deadLetter());

        self::assertCount(2, $this->published);
    }

    public function testANackOfAnEarlierPublishDoesNotStayForALaterOne(): void
    {
        $sink = $this->sink();
        $this->duringConfirmWait = function (): void {
            ($this->nackHandler)($this->published[array_key_last($this->published)]['message']);
        };
        try {
            $sink->store(self::deadLetter());
            self::fail('the first publish is nacked');
        } catch (RuntimeException) {
        }
        $this->duringConfirmWait = null;

        $sink->store(self::deadLetter());

        self::assertCount(2, $this->published);
    }
}
