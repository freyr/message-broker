<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Transport\Amqp;

use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Tests\Fixtures\RecordingLogger;
use Freyr\MessageBroker\Transport\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpRelay;
use PhpAmqpLib\Channel\AMQPChannel;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;

final class AmqpRelayShutdownTest extends TestCase
{
    public function testShutdownSwallowsReleaseFailureAndLogsWarning(): void
    {
        // shutdown() runs in run()'s finally: if the loop died because the DB
        // connection died, releaseLane() throws on that same dead connection and
        // would mask the root-cause error — the guard must swallow it.
        $store = $this->createStub(OutboxStore::class);
        $store->method('tryAcquireLane')
            ->willReturn(true);
        $store->method('lanePrefix')
            ->willReturn([]);
        $store->method('releaseLane')
            ->willThrowException(new RuntimeException('connection gone'));

        $logger = new RecordingLogger();
        $relay = new AmqpRelay(
            outbox: $store,
            amqp: $this->createStub(AMQPChannel::class),
            publish: new AmqpPublishConfig(exchange: 'orders_x'),
            contentType: JsonWireFormat::CONTENT_TYPE,
            lane: 'orders',
            logger: $logger,
        );

        self::assertSame(0, $relay->drainOnce(), 'acquires the stubbed lane');
        $relay->shutdown();
        $relay->shutdown(); // idempotent: the failed release is not retried

        $warnings = array_values(array_filter(
            $logger->records,
            static fn (array $r): bool => $r['level'] === LogLevel::WARNING,
        ));
        self::assertCount(1, $warnings, 'exactly one swallowed-release warning');
        self::assertArrayHasKey('exception', $warnings[0]['context']);
        self::assertSame('orders', $warnings[0]['context']['lane']);
    }
}
