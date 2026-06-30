<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Kafka;

use Closure;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Consumer\MessageDispatcher;
use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\ErrorHandler;
use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Retry\RetryAction;
use Freyr\MessageBroker\Serializer\Deserializer;
use Freyr\MessageBroker\Serializer\MalformedMessage;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Transport\PdoDeduplicationStore;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RdKafka\Conf;
use RdKafka\KafkaConsumer as RdKafkaConsumer;
use RdKafka\Message as KafkaMessage;
use RuntimeException;
use Throwable;

/**
 * Long-running Kafka worker implementing the three-stage consumer pipeline
 * (raw KafkaMessage → Deserializer → IncomingMessage → MessageDispatcher),
 * the Kafka twin of AmqpConsumer.
 *
 * Per delivery:
 *   deserialize            (MalformedMessage → dead-letter, commit offset, advance;
 *                           transient failures propagate → offset NOT committed → redelivery)
 *   in-process retry loop:
 *     BEGIN pdo transaction
 *       dedup acquire      (duplicate → COMMIT, advance)
 *       dispatcher->dispatch($incoming)
 *     COMMIT
 *   commit offset          (ONLY after the DB commit — at-least-once + dedup)
 *   dispatch exception → ROLLBACK → retryPolicy->decide(attempt):
 *     retry  : sleep(delay), retry IN PROCESS (partition stays blocked → FIFO)
 *     dlq    : dead_letters row, commit offset, advance
 *     discard: commit offset, advance
 *
 * Offset commit is injectable (default: synchronous commit) so failure between
 * the DB commit and the offset commit can be exercised in tests.
 *
 * This object runs a single `run()` to completion and must not be reused afterward:
 * `run()` closes its consumer in a `finally`, so a second `run()` on the same
 * instance would operate on a closed consumer.
 */
final class KafkaConsumer
{
    private bool $shouldStop = false;

    private int $handled = 0;

    private ?RdKafkaConsumer $consumer = null;

    /** @param (Closure(RdKafkaConsumer, KafkaMessage): void)|null $offsetCommitter */
    public function __construct(
        private readonly KafkaConsumerConfig $config,
        private readonly Deserializer $deserializer,
        private readonly MessageDispatcher $dispatcher,
        private readonly PDO $pdo,
        private readonly PdoDeduplicationStore $deduplication,
        private readonly KafkaRetryPolicy $retryPolicy,
        private readonly PdoDeadLetterStore $deadLetters,
        private readonly string $name = 'default', // dedup scope
        private readonly ?ErrorHandler $errorHandler = null,
        private readonly ?Closure $offsetCommitter = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?BrokerEvents $events = null,
    ) {}

    /**
     * Blocks until stopped (SIGTERM/SIGINT via pcntl), the optional message
     * limit is reached, or the topic stays silent for the idle window. Limit
     * and timeout exist for tests and batch operation.
     *
     * `$idleTimeoutSec = null` means run until stopped (no idle timeout); pass a positive
     * number for tests/batch operation. `0` is not a meaningful value (it would stop on
     * the first idle poll before any message).
     */
    public function run(?int $messageLimit = null, ?int $idleTimeoutSec = null): void
    {
        $this->registerSignalHandlers();
        $consumer = $this->consumer();
        $consumer->subscribe([$this->config->topic]);

        $idleMs = ($idleTimeoutSec ?? 0) * 1_000;
        $deadline = $idleTimeoutSec === null ? null : EpochMillis::now() + $idleMs;

        try {
            while (!$this->shouldStop) {
                if ($messageLimit !== null && $this->handled >= $messageLimit) {
                    break;
                }

                $message = $consumer->consume(1_000);

                switch ($message->err) {
                    case RD_KAFKA_RESP_ERR_NO_ERROR:
                        $this->handle($consumer, $message);
                        $deadline = $idleTimeoutSec === null ? null : EpochMillis::now() + $idleMs;
                        break;
                    case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                    case RD_KAFKA_RESP_ERR__TIMED_OUT:
                        if ($deadline !== null && EpochMillis::now() >= $deadline) {
                            $this->shouldStop = true; // idle window elapsed
                        }
                        break;
                    default:
                        throw new RuntimeException('Kafka consume error: '.$message->errstr());
                }
            }
        } finally {
            // Always leave the group promptly; uncommitted offsets stay
            // uncommitted (auto-commit is off) so redelivery is safe.
            $consumer->close();
        }
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    private function handle(RdKafkaConsumer $consumer, KafkaMessage $message): void
    {
        /** @var array<string, mixed> $headers */
        // @phpstan-ignore nullCoalesce.property (rdkafka stubs declare $headers as array<string, string>, but the C extension returns null when no headers are present on the message)
        $headers = $message->headers ?? [];

        try {
            $incoming = $this->deserializer->deserialize((string) $message->payload, $headers);
        } catch (MalformedMessage $error) {
            // Malformed never improves: dead-letter and advance past the poison.
            $this->deadLetterRaw($message, $headers, $error);
            $this->commitOffset($consumer, $message);
            ++$this->handled;

            return;
        } catch (Throwable $error) {
            // Transient (e.g. registry down): surface, do NOT commit — the
            // message is redelivered when the dependency recovers.
            $context = [
                'topic' => $this->config->topic,
                'stage' => 'deserialize',
                'partition' => $message->partition,
                'offset' => $message->offset,
            ];
            $this->logger->error('Consumer deserialize failed (transient); redelivering', [
                'exception' => $error,
            ] + $context);
            $this->errorHandler?->handle($error, $context);

            throw $error;
        }

        $attempt = 0;
        while (true) {
            ++$attempt;
            try {
                $this->pdo->beginTransaction();

                if (!$this->deduplication->acquire($incoming, $this->name)) {
                    $this->pdo->commit();
                    $this->events?->record(BrokerEvents::DEDUPLICATED, [
                        'consumer' => $this->name,
                        'message_id' => $incoming->messageId,
                        'message_name' => $incoming->messageName,
                    ]);
                    break; // duplicate → advance
                }

                $this->dispatcher->dispatch($incoming);
                $this->pdo->commit();
                $this->events?->record(BrokerEvents::DISPATCHED, [
                    'consumer' => $this->name,
                    'message_id' => $incoming->messageId,
                    'message_name' => $incoming->messageName,
                ]);
                break; // success → advance
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                $decision = $this->retryPolicy->decide($attempt, $error);

                $context = [
                    'topic' => $this->config->topic,
                    'message_id' => $incoming->messageId,
                    'message_name' => $incoming->messageName,
                    'attempt' => $attempt,
                    'action' => $decision->action->name,
                ];
                $this->logger->warning('Consumer dispatch failed', [
                    'exception' => $error,
                ] + $context);
                $this->errorHandler?->handle($error, $context);

                if ($decision->action === RetryAction::Retry) {
                    if ($decision->delayMs > 0) {
                        usleep($decision->delayMs * 1_000);
                    }

                    continue; // retry in process; the partition stays blocked (FIFO)
                }

                if ($decision->action === RetryAction::DeadLetter) {
                    $this->deadLetterIncoming($incoming, $error, $attempt);
                }

                break; // DeadLetter or Discard → advance
            }
        }

        $this->commitOffset($consumer, $message);
        ++$this->handled;
    }

    private function commitOffset(RdKafkaConsumer $consumer, KafkaMessage $message): void
    {
        if ($this->offsetCommitter !== null) {
            ($this->offsetCommitter)($consumer, $message);

            return;
        }

        $consumer->commit($message); // synchronous; commits this message's offset + 1
    }

    /** @param array<string, mixed> $headers */
    private function deadLetterRaw(KafkaMessage $message, array $headers, Throwable $error): void
    {
        // Best-effort triage from the x-message-* headers; raw dead letters
        // are never replayed, so this only improves dlq:show output.
        $messageId = 'unknown';
        $messageName = 'unknown';
        try {
            $meta = MetadataHeader::parse($headers);
            $messageId = $meta['message_id'];
            $messageName = $meta['message_name'];
        } catch (MalformedMessage) {
            // headers missing/garbled — keep the 'unknown' triage values
        }

        $this->storeDeadLetter($messageId, $messageName, (string) $message->payload, $headers, $error, 1);
    }

    private function deadLetterIncoming(IncomingMessage $incoming, Throwable $error, int $attempt): void
    {
        // Store the reconstructed canonical document (readable, replayable),
        // mirroring the AMQP consumer.
        $document = json_encode([
            'metadata' => [
                'message_name' => $incoming->messageName,
                'message_id' => $incoming->messageId,
                'created_at' => $incoming->createdAt,
            ],
            'payload' => $incoming->payload,
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

        $this->storeDeadLetter(
            $incoming->messageId,
            $incoming->messageName,
            $document,
            $incoming->headers,
            $error,
            $attempt
        );
    }

    /** @param array<string, mixed> $headers */
    private function storeDeadLetter(
        string $messageId,
        string $messageName,
        string $body,
        array $headers,
        Throwable $error,
        int $attempt,
    ): void {
        $this->deadLetters->store(DeadLetter::fromFailure(
            source: $this->config->topic,
            messageId: $messageId,
            messageName: $messageName,
            body: $body,
            headers: $headers,
            error: $error,
            attempts: $attempt,
        ));

        $this->events?->record(BrokerEvents::DEAD_LETTERED, [
            'source' => $this->config->topic,
            'message_id' => $messageId,
            'message_name' => $messageName,
        ]);
    }

    private function consumer(): RdKafkaConsumer
    {
        if ($this->consumer !== null) {
            return $this->consumer;
        }

        $conf = new Conf();
        $conf->set('metadata.broker.list', $this->config->brokers);
        $conf->set('group.id', $this->config->groupId);
        $conf->set('auto.offset.reset', $this->config->autoOffsetReset);
        $conf->set('enable.auto.commit', 'false');
        $conf->set('enable.partition.eof', 'true');

        return $this->consumer = new RdKafkaConsumer($conf);
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
