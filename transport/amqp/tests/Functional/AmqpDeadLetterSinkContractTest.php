<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp\Tests\Functional;

use Freyr\MessageBroker\DeadLetter\DeadLetterBuilder;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\DeadLetter\DeadLetterImporter;
use Freyr\MessageBroker\DeadLetter\DeadLetterSink;
use Freyr\MessageBroker\Serializer\JsonDeserializer;
use Freyr\MessageBroker\Testing\DeadLetterSinkContractTestCase;
use Freyr\MessageBroker\Testing\InMemoryDeadLetterStore;
use Freyr\MessageBroker\Testing\TestIsolation;
use Freyr\MessageBroker\Transport\Amqp\AmqpDeadLetterConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpDeadLetterImport;
use Freyr\MessageBroker\Transport\Amqp\AmqpDeadLetterSink;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * The AMQP dead letter sink keeps the contract of the DeadLetterSink port.
 * The hook reads the dead letter queue with basic.get and decodes each
 * message through DeadLetterImporter (case 1, the header x-dead-letter).
 */
final class AmqpDeadLetterSinkContractTest extends DeadLetterSinkContractTestCase
{
    use TestIsolation;

    private const string EXCHANGE = 'mb_sink_contract_dlx';

    private const string QUEUE = 'mb_sink_contract_dlq';

    private static AMQPStreamConnection $amqp;

    private AMQPChannel $channel;

    private AMQPChannel $sinkChannel;

    /** The rows that the hook imported from the queue: the queue keeps no message that the import acked. */
    private InMemoryDeadLetterStore $imported;

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
        $this->imported = new InMemoryDeadLetterStore();
        $this->channel = self::$amqp->channel();
        $this->channel->exchange_declare(self::isolated(self::EXCHANGE), 'direct', false, true, false);
        $this->channel->queue_declare(self::isolated(self::QUEUE), false, true, false, false);
        $this->channel->queue_bind(self::isolated(self::QUEUE), self::isolated(self::EXCHANGE), self::isolated(self::QUEUE));
        $this->channel->queue_purge(self::isolated(self::QUEUE));
    }

    protected function tearDown(): void
    {
        if (isset($this->sinkChannel)) {
            $this->sinkChannel->close();
        }
        $this->channel->close();
    }

    protected function createSink(): DeadLetterSink
    {
        $this->sinkChannel = self::$amqp->channel();

        return new AmqpDeadLetterSink(
            $this->sinkChannel,
            new AmqpDeadLetterConfig(self::isolated(self::EXCHANGE), self::isolated(self::QUEUE)),
        );
    }

    protected function storedDeadLetters(): array
    {
        $importer = new DeadLetterImporter($this->imported, new JsonDeserializer(), new DeadLetterBuilder());
        (new AmqpDeadLetterImport($this->channel, self::isolated(self::QUEUE), $importer))->run();

        return $this->imported->list(new DeadLetterFilter());
    }
}
