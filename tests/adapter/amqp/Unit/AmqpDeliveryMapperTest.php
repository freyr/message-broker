<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Amqp\Tests\Unit;

use DateTime;
use Freyr\MessageBroker\Adapter\Amqp\AmqpDeliveryMapper;
use Freyr\MessageBroker\Adapter\Amqp\WaitQueuePublisher;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The mapping AMQPMessage → Delivery that the consumer and the dead letter import share. */
final class AmqpDeliveryMapperTest extends TestCase
{
    use RecordsChannel;

    protected function setUp(): void
    {
        $this->setUpChannel();
    }

    private static function mapper(): AmqpDeliveryMapper
    {
        return new AmqpDeliveryMapper('orders_q.dlq');
    }

    public function testMappingCarriesBodyHeadersSourceAndFallbackMessageId(): void
    {
        $message = $this->delivery(1, 'm-1', [
            'x-custom' => 'kept',
        ], body: 'raw-body', propertyMessageId: 'prop-id');

        $delivery = self::mapper()->map($message);

        self::assertSame('raw-body', $delivery->body);
        self::assertSame('orders_q.dlq', $delivery->source, 'the source given to the mapper');
        self::assertSame('prop-id', $delivery->fallbackMessageId);
        self::assertSame([
            'x-message-id' => 'm-1',
            'x-message-name' => 'order.placed',
            'x-created-at' => 1_765_476_000_123,
            'x-custom' => 'kept',
        ], $delivery->headers);
        self::assertSame(1, $delivery->attempt);
        self::assertNull($delivery->deliveryCount);
    }

    public function testMappingOfAMessageWithoutPropertiesUsesDefaults(): void
    {
        $delivery = self::mapper()->map(new AMQPMessage('b'));

        self::assertSame([], $delivery->headers);
        self::assertNull($delivery->fallbackMessageId);
        self::assertSame(1, $delivery->attempt);
        self::assertNull($delivery->deliveryCount);
    }

    public function testMappingKeepsTheAttemptHeader(): void
    {
        $delivery = self::mapper()->map($this->delivery(1, 'm-1', [
            'x-attempt' => 4,
        ]));

        self::assertSame(4, $delivery->attempt);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidAttemptHeaders(): iterable
    {
        yield 'string' => ['3'];
        yield 'zero' => [0];
        yield 'negative' => [-4];
        yield 'float' => [2.0];
    }

    #[DataProvider('invalidAttemptHeaders')]
    public function testAnInvalidAttemptHeaderCountsAsTheFirstAttempt(mixed $header): void
    {
        $delivery = self::mapper()->map($this->delivery(1, 'm-1', [
            'x-attempt' => $header,
        ]));

        self::assertSame(1, $delivery->attempt);
    }

    public function testMappingCarriesTheDeliveryCountOfTheTransport(): void
    {
        $delivery = self::mapper()->map($this->delivery(1, 'm-1', [
            'x-delivery-count' => 3,
        ]));

        self::assertSame(3, $delivery->deliveryCount);
    }

    public function testMappingReadsTheDeliveryCountHeaderOfTheWaitQueuePublisher(): void
    {
        $delivery = self::mapper()->map($this->delivery(1, 'm-1', [
            WaitQueuePublisher::DELIVERY_COUNT_HEADER => 7,
        ]));

        self::assertSame(7, $delivery->deliveryCount);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidDeliveryCounts(): iterable
    {
        yield 'string' => ['3'];
        yield 'float' => [2.0];
        yield 'bool' => [true];
    }

    #[DataProvider('invalidDeliveryCounts')]
    public function testADeliveryCountThatIsNotAnIntegerIsIgnored(mixed $header): void
    {
        $delivery = self::mapper()->map($this->delivery(1, 'm-1', [
            'x-delivery-count' => $header,
        ]));

        self::assertNull($delivery->deliveryCount);
    }

    public function testAPropertyMessageIdThatIsNotAStringIsIgnored(): void
    {
        $message = new AMQPMessage('b', [
            'message_id' => 12,
            'application_headers' => new AMQPTable([]),
        ]);

        self::assertNull(self::mapper()->map($message)->fallbackMessageId);
    }

    public function testNestedHeadersAndTimestampsBecomeNativeValues(): void
    {
        $time = new DateTime('@1795219200');
        $message = new AMQPMessage('b', [
            'application_headers' => new AMQPTable([
                'x-death' => [[
                    'queue' => 'orders_q',
                    'count' => 1,
                    'time' => $time,
                ]],
            ]),
        ]);

        $headers = self::mapper()->map($message)->headers;

        self::assertSame('orders_q', $headers['x-death'][0]['queue']);
        self::assertSame(1, $headers['x-death'][0]['count']);
        self::assertInstanceOf(\DateTimeInterface::class, $headers['x-death'][0]['time']);
        self::assertSame(1_795_219_200, $headers['x-death'][0]['time']->getTimestamp());
    }
}
