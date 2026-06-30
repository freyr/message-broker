<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Kafka;

use Freyr\MessageBroker\ErrorHandler;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Transport\IdleSleep;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RdKafka\Conf;
use RdKafka\Producer;
use RdKafka\ProducerTopic;
use RuntimeException;
use Throwable;

/**
 * Dedicated Kafka relay: drains ONE outbox lane to ONE topic, preserving total
 * order within the lane (D17). The producer is idempotent with the
 * murmur2_random partitioner, so message_key → partition is stable and order
 * survives broker retries → strict per-key FIFO.
 *
 * Batched drain mirrors the AMQP relay: publish the whole eligible prefix in id
 * order, then ONE flush confirms the batch (the rdkafka analog of publisher
 * confirms), then rows are deleted. On any failure nothing is deleted — the
 * head backs off and the batch republishes in order on a later pass; consumer
 * deduplication absorbs the duplicates (at-least-once). No DLQ on this path.
 *
 * The relay OWNS its producer exclusively. Kafka/SQS/AMQP have no shared relay
 * interface — each is a concrete class.
 */
final class KafkaRelay
{
    private bool $laneAcquired = false;

    private bool $shouldStop = false;

    private readonly Backoff $backoff;

    private ?Producer $producer = null;

    private ?ProducerTopic $topic = null;

    /** @var list<string> delivery-report errors observed during the current batch */
    private array $deliveryErrors = [];

    public function __construct(
        private readonly OutboxStore $outbox,
        private readonly KafkaPublishConfig $publish,
        private readonly string $lane = 'default',
        private readonly int $batchSize = 100,
        ?Backoff $backoff = null,
        private readonly ?ErrorHandler $errorHandler = null,
        private readonly int $idleSleepMs = 200,
        private readonly int $flushTimeoutMs = 5_000,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->backoff = $backoff ?? Backoff::exponential(initialDelayMs: 1_000, maxDelayMs: 300_000);
    }

    /** Long-running entrypoint; stops on SIGTERM/SIGINT when pcntl is available. */
    public function run(): void
    {
        $this->registerSignalHandlers();

        try {
            while (!$this->shouldStop) {
                if ($this->drainOnce() === 0) {
                    usleep(IdleSleep::micros($this->idleSleepMs, intdiv($this->idleSleepMs, 4)));
                }
            }
        } finally {
            $this->shutdown();
        }
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    /** Release the owned lane so a standby relay takes over at once. Idempotent. */
    public function shutdown(): void
    {
        if ($this->laneAcquired) {
            $this->outbox->releaseLane($this->lane);
            $this->laneAcquired = false;
        }
    }

    /** One pass over the owned lane. @return int rows published and deleted */
    public function drainOnce(): int
    {
        if (!$this->laneAcquired) {
            $this->laneAcquired = $this->outbox->tryAcquireLane($this->lane);
            if (!$this->laneAcquired) {
                return 0; // owned elsewhere — retry next tick so a standby can take over
            }
        }

        $prefix = $this->outbox->lanePrefix($this->lane, $this->batchSize);

        // Head-of-line: if the first row is backing off, the whole lane waits.
        if ($prefix === [] || $prefix[0]->availableAt > EpochMillis::now()) {
            return 0;
        }

        try {
            $this->publishBatch($prefix);
        } catch (Throwable $error) {
            $this->backOffHead($prefix[0], $error);

            return 0;
        }

        $this->outbox->deleteBatch(array_map(static fn (OutboxRecord $record): string => $record->id, $prefix));

        return count($prefix);
    }

    /** @param non-empty-list<OutboxRecord> $batch */
    private function publishBatch(array $batch): void
    {
        $producer = $this->producer();
        $topic = $this->topic ??= $producer->newTopic($this->publish->topic);
        $this->deliveryErrors = [];

        foreach ($batch as $record) {
            // Explode the metadata column into individual x-message-* headers
            // (E7); produce-time headers ride alongside, the envelope wins on
            // collision. Kafka headers are untyped bytes — stringify every value.
            // headers + envelope are both int|string; cast to string for Kafka's untyped header bytes
            /** @var array<string, int|string> $rawHeaders */
            $rawHeaders = array_merge($record->headers, MetadataHeader::explode($record->metadata));
            $headers = array_map(static fn (int|string $value): string => (string) $value, $rawHeaders);

            // RD_KAFKA_PARTITION_UA: the murmur2 partitioner derives the
            // partition from the key, so same key → same partition (per-key FIFO).
            // The body is opaque wire bytes; the relay never parses it.
            $topic->producev(
                RD_KAFKA_PARTITION_UA,
                0,
                $record->body,
                $record->key,
                $headers,
                $record->createdAt, // epoch ms → native Kafka record timestamp
            );
            $producer->poll(0); // serve delivery-report callbacks
        }

        // One confirm wait for the whole batch (the analog of AMQP confirms).
        $result = $producer->flush($this->flushTimeoutMs);
        if ($result !== RD_KAFKA_RESP_ERR_NO_ERROR) {
            throw new RuntimeException('Kafka flush did not confirm the batch: '.rd_kafka_err2str($result));
        }

        // The delivery-report callback (setDrMsgCb, fired by poll()) mutates
        // $this->deliveryErrors; phpstan cannot see that cross-callback write and
        // narrows the property to array{} after the reset above.
        // @phpstan-ignore notIdentical.alwaysFalse
        if ($this->deliveryErrors !== []) {
            throw new RuntimeException('Kafka delivery failed: '.implode('; ', $this->deliveryErrors));
        }
    }

    private function producer(): Producer
    {
        if ($this->producer !== null) {
            return $this->producer;
        }

        $conf = new Conf();
        $conf->set('metadata.broker.list', $this->publish->brokers);
        // Idempotence mandates acks=all + capped in-flight and preserves order
        // across retries. murmur2_random matches Debezium's Java partitioner.
        $conf->set('enable.idempotence', 'true');
        $conf->set('partitioner', 'murmur2_random');
        $conf->set('linger.ms', '50');
        $conf->setDrMsgCb(function (Producer $producer, \RdKafka\Message $message): void {
            if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                $this->deliveryErrors[] = $message->errstr();
            }
        });

        return $this->producer = new Producer($conf);
    }

    private function backOffHead(OutboxRecord $head, Throwable $error): void
    {
        $attempt = $head->attempts + 1;
        $delayMs = $this->backoff->delayForAttempt($attempt);
        $this->outbox->scheduleRetry($head->id, EpochMillis::now() + $delayMs);

        $context = [
            'lane' => $this->lane,
            'message_id' => $head->id,
            'message_name' => $head->messageName(),
            'attempt' => $attempt,
            'retry_in_ms' => $delayMs,
        ];
        $this->logger->warning('Relay publish failed; lane backing off', [
            'exception' => $error,
        ] + $context);
        $this->errorHandler?->handle($error, $context);
    }

    private function registerSignalHandlers(): void
    {
        if (!extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->stop());
        pcntl_signal(SIGINT, fn () => $this->stop());
    }
}
