<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Kafka;

use RdKafka\Conf;
use RdKafka\Producer;

final class KafkaConnectivityTest extends KafkaTestCase
{
    public function testProducerReachesTheBrokerAndFetchesMetadata(): void
    {
        $conf = new Conf();
        $conf->set('metadata.broker.list', self::brokers());
        $producer = new Producer($conf);

        // all_topics=true forces a metadata round-trip to the broker.
        $metadata = $producer->getMetadata(true, null, 5_000);

        self::assertGreaterThanOrEqual(1, count($metadata->getBrokers()));
    }
}
