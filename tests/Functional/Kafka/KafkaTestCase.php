<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Kafka;

use Freyr\MessageBroker\Serializer\Format;
use Freyr\MessageBroker\Tests\Functional\FunctionalTestCase;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RdKafka\Message as KafkaMessage;

/**
 * Functional base for the Kafka transport. Each test uses a UNIQUE topic and
 * consumer group (committed offsets/group state persist on the broker), so
 * nothing bleeds across tests. The broker auto-creates topics with 3
 * partitions (KAFKA_NUM_PARTITIONS in compose).
 */
abstract class KafkaTestCase extends FunctionalTestCase
{
    /**
     * MySQL's JSON column type rejects non-JSON bytes (e.g. malformed-body DLQ
     * tests). Using Format::Avro switches the MySQL body column to LONGBLOB,
     * which accepts any bytes. PostgreSQL always uses BYTEA regardless of this
     * setting. The wire format used by producers/consumers is still JSON.
     */
    protected static function outboxFormat(): Format
    {
        return Format::Avro;
    }

    public static function tearDownAfterClass(): void
    {
        // Release session-scoped advisory locks (MySQL: GET_LOCK, PG: advisory)
        // so subsequent test classes can acquire lane locks on their own fresh
        // connections. Ignores errors — the connection may have been replaced.
        try {
            if (self::isPostgres()) {
                self::$pdo->exec('SELECT pg_advisory_unlock_all()');
            } else {
                self::$pdo->exec('SELECT RELEASE_ALL_LOCKS()');
            }
        } catch (\Throwable) {
            // Ignore.
        }
        parent::tearDownAfterClass();
    }

    protected static function brokers(): string
    {
        return getenv('KAFKA_BROKERS') ?: 'kafka:9092';
    }

    protected function uniqueTopic(string $prefix = 'mb_topic'): string
    {
        return $prefix.'_'.uniqid();
    }

    protected function uniqueGroup(string $prefix = 'mb_grp'): string
    {
        return $prefix.'_'.uniqid();
    }

    /**
     * Read back up to $max messages from $topic with a fresh consumer group
     * (from earliest), in per-partition consumption order. Stops at $max or
     * after $idleMs of silence.
     *
     * @return list<KafkaMessage>
     */
    protected function consumeAll(string $topic, int $max, int $idleMs = 8_000): array
    {
        $conf = new Conf();
        $conf->set('metadata.broker.list', self::brokers());
        $conf->set('group.id', $this->uniqueGroup('readback'));
        $conf->set('auto.offset.reset', 'earliest');
        $conf->set('enable.auto.commit', 'false');
        $conf->set('enable.partition.eof', 'true');

        $consumer = new KafkaConsumer($conf);
        $consumer->subscribe([$topic]);

        $messages = [];
        $deadline = (int) (microtime(true) * 1000) + $idleMs;
        try {
            while (count($messages) < $max && (int) (microtime(true) * 1000) < $deadline) {
                $message = $consumer->consume(1_000);
                if ($message->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
                    $messages[] = $message;
                    $deadline = (int) (microtime(true) * 1000) + $idleMs;
                }
                // TIMED_OUT / PARTITION_EOF: keep polling until the deadline.
            }
        } finally {
            $consumer->close();
        }

        return $messages;
    }
}
