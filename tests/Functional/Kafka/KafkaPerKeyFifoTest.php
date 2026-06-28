<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Kafka;

use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use Freyr\MessageBroker\Tests\Fixtures\OrderPlaced;
use Freyr\MessageBroker\Transport\Kafka\KafkaPublishConfig;
use Freyr\MessageBroker\Transport\Kafka\KafkaRelay;

final class KafkaPerKeyFifoTest extends KafkaTestCase
{
    public function testEachKeyPreservesProductionOrderAcrossPartitions(): void
    {
        $store = new OutboxStore(self::$pdo, static::platform());
        $producer = new OutboxProducer($store, new JsonWireFormat(), lane: 'orders');
        $topic = $this->uniqueTopic('mb_fifo');

        // Three keys, four messages each, interleaved in production order.
        $keys = ['o-1', 'o-2', 'o-3'];
        /** @var array<string, list<string>> $expected message ids per key, in produce order */
        $expected = [
            'o-1' => [],
            'o-2' => [],
            'o-3' => [],
        ];
        for ($round = 1; $round <= 4; ++$round) {
            foreach ($keys as $key) {
                $message = OrderPlaced::create($key, $round * 100);
                $producer->produce($message);
                $expected[$key][] = $message->id;
            }
        }

        $relay = new KafkaRelay(
            outbox: $store,
            publish: new KafkaPublishConfig(brokers: self::brokers(), topic: $topic),
            lane: 'orders',
        );
        self::assertSame(12, $relay->drainOnce());

        $messages = $this->consumeAll($topic, max: 12);
        self::assertCount(12, $messages);

        // Group the read-back by key; within a key it must match produce order.
        $actual = [
            'o-1' => [],
            'o-2' => [],
            'o-3' => [],
        ];
        foreach ($messages as $message) {
            $actual[(string) $message->key][] = $message->headers[MetadataHeader::MESSAGE_ID];
        }

        foreach ($keys as $key) {
            self::assertSame($expected[$key], $actual[$key], "key {$key} must preserve strict per-key FIFO order");
        }
    }
}
