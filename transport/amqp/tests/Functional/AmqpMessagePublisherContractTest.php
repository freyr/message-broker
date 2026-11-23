<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp\Tests\Functional;

use Freyr\MessageBroker\Outbox\OutboxPublisher;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Testing\OutboxPublisherContractTestCase;
use Freyr\MessageBroker\Testing\TestIsolation;
use Freyr\MessageBroker\Transport\Amqp\AmqpMessagePublisher;
use Freyr\MessageBroker\Transport\Amqp\AmqpPublishConfig;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/** The AMQP publisher keeps the contract of the OutboxPublisher port; arrival is read from a bound queue. */
final class AmqpMessagePublisherContractTest extends OutboxPublisherContractTestCase
{
    use TestIsolation;

    private const string EXCHANGE = 'mb_publisher_contract';
    private const string QUEUE = 'mb_publisher_contract_q';

    private static AMQPStreamConnection $amqp;
    private AMQPChannel $channel;

    public static function setUpBeforeClass(): void
    {
        self::$amqp = new AMQPStreamConnection(
            host: getenv('AMQP_HOST') ?: 'rabbitmq',
            port: (int) (getenv('AMQP_PORT') ?: 5672),
            user: getenv('AMQP_USER') ?: 'guest',
            password: getenv('AMQP_PASSWORD') ?: 'guest',
            vhost: getenv('AMQP_VHOST') ?: '/',
        );
    }

    public static function tearDownAfterClass(): void
    {
        $channel = self::$amqp->channel();
        $channel->queue_delete(self::isolated(self::QUEUE));
        $channel->exchange_delete(self::isolated(self::EXCHANGE));
        $channel->close();
        self::$amqp->close();
    }

    protected function setUp(): void
    {
        $this->channel = self::$amqp->channel();
        $this->channel->exchange_declare(self::isolated(self::EXCHANGE), 'topic', false, true, false);
        $this->channel->queue_declare(self::isolated(self::QUEUE), false, true, false, false);
        $this->channel->queue_bind(self::isolated(self::QUEUE), self::isolated(self::EXCHANGE), '#');
        $this->channel->queue_purge(self::isolated(self::QUEUE));
    }

    protected function tearDown(): void
    {
        $this->channel->close();
    }

    protected function createPublisher(): OutboxPublisher
    {
        return new AmqpMessagePublisher(
            self::$amqp->channel(),
            new AmqpPublishConfig(exchange: self::isolated(self::EXCHANGE)),
            JsonWireFormat::CONTENT_TYPE,
        );
    }

    protected function assertDelivered(array $records): void
    {
        $arrived = [];
        for ($i = 0; $i < count($records); ++$i) {
            $delivery = $this->channel->basic_get(self::isolated(self::QUEUE), no_ack: true);
            self::assertNotNull($delivery, 'the broker confirmed the batch, so each message is in the queue');
            $arrived[] = (string) $delivery->get('message_id');
        }
        self::assertNull($this->channel->basic_get(self::isolated(self::QUEUE), no_ack: true), 'and no message more');
        self::assertEqualsCanonicalizing(
            array_map(static fn (OutboxRecord $record): string => $record->id, $records),
            $arrived,
        );
    }
}
