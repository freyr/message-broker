<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Transport\Amqp;

use Freyr\MessageBroker\Consumer\CallableDispatcher;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Serializer\Deserializer;
use Freyr\MessageBroker\Serializer\JsonDeserializer;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use Freyr\MessageBroker\Tests\Fixtures\RecordingErrorHandler;
use Freyr\MessageBroker\Tests\Fixtures\RecordingEvents;
use Freyr\MessageBroker\Tests\Fixtures\RecordingLogger;
use Freyr\MessageBroker\Transport\Amqp\AmqpConsumer;
use Freyr\MessageBroker\Transport\Amqp\AmqpQueueConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpRetryPolicy;
use Freyr\MessageBroker\Transport\DeduplicationStore;
use PDO;
use PDOException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;

/**
 * The consumer against a recording channel and mocked collaborators: every
 * outward effect (AMQP calls, transaction calls, logs, events, dead letters)
 * is recorded and asserted without a broker.
 */
final class AmqpConsumerTest extends TestCase
{
    private const string QUEUE = 'orders';

    /** @var list<AMQPMessage> */
    private array $pending = [];

    private bool $timeoutWhenEmpty = false;

    /** @var list<int> */
    private array $acked = [];

    /** @var list<array{message: AMQPMessage, exchange: string, routingKey: string}> */
    private array $published = [];

    /** @var list<array<int, mixed>> */
    private array $declared = [];

    /** @var list<mixed> */
    private array $waitTimeouts = [];

    /** @var list<mixed> */
    private array $qosCalls = [];

    private int $confirmSelects = 0;

    private int $nackHandlers = 0;

    /** @var list<mixed> */
    private array $pendingAckTimeouts = [];

    private ?\Closure $nackHandler = null;

    private bool $nackOnConfirm = false;

    private bool $cancelled = false;

    private string $consumedQueue = '';

    /** @var list<DeadLetter> */
    private array $deadLetters = [];

    /** @var list<IncomingMessage> */
    private array $dispatched = [];

    private ?\Closure $failingDispatch = null;

    private RecordingLogger $logger;

    private RecordingEvents $events;

    private RecordingErrorHandler $errorHandler;

    private AMQPChannel&Stub $channel;

    private PDO&Stub $pdo;

    /** @var list<string> */
    private array $transactionCalls = [];

    private bool $duplicate = false;

    private ?\Closure $beginFails = null;

    private bool $rollbackFails = false;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->events = new RecordingEvents();
        $this->errorHandler = new RecordingErrorHandler();
        $this->channel = $this->createStub(AMQPChannel::class);
        $this->pdo = $this->createStub(PDO::class);

        $this->channel->method('basic_qos')->willReturnCallback(function (...$args): void {
            $this->qosCalls[] = $args;
        });
        $this->channel->method('basic_consume')->willReturnCallback(function (...$args): string {
            foreach ($args as $arg) {
                if ($arg instanceof \Closure) {
                    $this->callback = $arg;
                }
            }
            $this->consumedQueue = (string) ($args[0] ?? '');

            return 'ctag';
        });
        $this->channel->method('is_consuming')->willReturnCallback(
            fn (): bool => $this->pending !== [] || $this->timeoutWhenEmpty,
        );
        $this->channel->method('wait')->willReturnCallback(function (...$args) {
            $this->waitTimeouts[] = $args;
            if ($this->pending === []) {
                throw new AMQPTimeoutException('idle');
            }
            ($this->callback)(array_shift($this->pending));

            return null;
        });
        $this->channel->method('basic_cancel')->willReturnCallback(function (): void {
            $this->cancelled = true;
        });
        $this->channel->method('basic_ack')->willReturnCallback(function (int $tag): void {
            $this->acked[] = $tag;
        });
        $this->channel->method('confirm_select')->willReturnCallback(function (): void {
            ++$this->confirmSelects;
        });
        $this->channel->method('set_nack_handler')->willReturnCallback(function (\Closure $handler): void {
            ++$this->nackHandlers;
            $this->nackHandler = $handler;
        });
        $this->channel->method('queue_declare')->willReturnCallback(function (...$args): void {
            $this->declared[] = $args;
        });
        $this->channel->method('basic_publish')->willReturnCallback(
            function (AMQPMessage $message, string $exchange, string $routingKey): void {
                $this->published[] = [
                    'message' => $message,
                    'exchange' => $exchange,
                    'routingKey' => $routingKey,
                ];
            },
        );
        $this->channel->method('wait_for_pending_acks')->willReturnCallback(function ($timeout): void {
            $this->pendingAckTimeouts[] = $timeout;
            if ($this->nackOnConfirm && $this->nackHandler !== null) {
                ($this->nackHandler)();
            }
        });

        $this->pdo->method('beginTransaction')->willReturnCallback(function (): bool {
            $this->transactionCalls[] = 'begin';
            if ($this->beginFails !== null) {
                throw ($this->beginFails)();
            }

            return true;
        });
        $this->pdo->method('commit')->willReturnCallback(function (): bool {
            $this->transactionCalls[] = 'commit';

            return true;
        });
        $this->pdo->method('rollBack')->willReturnCallback(function (): bool {
            $this->transactionCalls[] = 'rollback';
            if ($this->rollbackFails) {
                throw new PDOException('connection gone');
            }

            return true;
        });
    }

    private ?\Closure $callback = null;

    private function consumer(
        ?Deserializer $deserializer = null,
        int $maxAttempts = 3,
        int $prefetch = 7,
        int $confirmTimeoutSec = 9,
        bool $withObservers = true,
    ): AmqpConsumer {
        $dedup = $this->createStub(DeduplicationStore::class);
        $dedup->method('acquire')->willReturnCallback(fn (): bool => !$this->duplicate);

        $store = $this->createStub(DeadLetterStore::class);
        $store->method('store')->willReturnCallback(function (DeadLetter $letter): void {
            $this->deadLetters[] = $letter;
        });

        return new AmqpConsumer(
            channel: $this->channel,
            queue: new AmqpQueueConfig(self::QUEUE, prefetch: $prefetch),
            deserializer: $deserializer ?? new JsonDeserializer(),
            dispatcher: new CallableDispatcher(function (IncomingMessage $incoming): void {
                if ($this->failingDispatch !== null) {
                    ($this->failingDispatch)($incoming);
                }
                $this->dispatched[] = $incoming;
            }),
            pdo: $this->pdo,
            deduplication: $dedup,
            retryPolicy: new AmqpRetryPolicy(
                maxAttempts: $maxAttempts,
                backoff: Backoff::exponential(initialDelayMs: 100, maxDelayMs: 100),
            ),
            deadLetters: $store,
            name: 'orders_consumer',
            errorHandler: $withObservers ? $this->errorHandler : null,
            logger: $this->logger,
            events: $withObservers ? $this->events : null,
            confirmTimeoutSec: $confirmTimeoutSec,
        );
    }

    /** @param array<string, mixed> $extraHeaders */
    private function delivery(
        int $tag = 1,
        string $messageId = 'm-1',
        array $extraHeaders = [],
        string $body = '{"order_id":"o-1"}',
        bool $withEnvelope = true,
        ?string $propertyMessageId = null,
    ): AMQPMessage {
        $headers = ($withEnvelope ? MetadataHeader::envelope($messageId, 'order.placed', 1_765_476_000_123) : [])
            + $extraHeaders;
        $properties = [
            'application_headers' => new AMQPTable($headers),
        ];
        if ($propertyMessageId !== null) {
            $properties['message_id'] = $propertyMessageId;
        }
        $message = new AMQPMessage($body, $properties);
        $message->setChannel($this->channel);
        $message->setDeliveryInfo($tag, false, '', self::QUEUE);

        return $message;
    }

    // run loop

    public function testRunSetsPrefetchConsumesTheQueueAndCancelsTheConsumer(): void
    {
        $this->pending = [$this->delivery()];

        $this->consumer(prefetch: 7)->run(messageLimit: 1);

        self::assertSame([[0, 7, false]], $this->qosCalls, 'prefetch is per consumer, not per channel');
        self::assertSame(self::QUEUE, $this->consumedQueue);
        self::assertTrue($this->cancelled);
    }

    public function testRunBlocksWithoutTimeoutWhenNoIdleTimeoutIsSet(): void
    {
        $this->pending = [$this->delivery()];

        $this->consumer()->run(messageLimit: 1);

        self::assertSame([[null, false, 0]], $this->waitTimeouts, 'timeout 0 means block');
    }

    public function testRunPassesTheIdleTimeoutToTheChannelWait(): void
    {
        $this->pending = [$this->delivery()];

        $this->consumer()->run(messageLimit: 1, idleTimeoutSec: 3);

        self::assertSame([[null, false, 3]], $this->waitTimeouts);
    }

    public function testRunReturnsWhenTheQueueStaysSilentForTheIdleWindow(): void
    {
        $this->timeoutWhenEmpty = true;

        $this->consumer()->run(idleTimeoutSec: 2);

        self::assertCount(1, $this->waitTimeouts);
        self::assertTrue($this->cancelled);
    }

    public function testRunStopsAfterTheMessageLimit(): void
    {
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2'), $this->delivery(3, 'm-3')];

        $this->consumer()->run(messageLimit: 2);

        self::assertSame([1, 2], $this->acked);
        self::assertCount(1, $this->pending);
    }

    public function testStopEndsTheLoopAfterTheCurrentDelivery(): void
    {
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2')];
        $consumer = $this->consumer();
        $this->failingDispatch = function () use (&$consumer): void {
            $consumer->stop();
        };

        $consumer->run();

        self::assertSame([1], $this->acked, 'the second delivery is not consumed after stop()');
        self::assertCount(1, $this->pending);
        self::assertTrue($this->cancelled);
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testSigtermAndSigintStopTheConsumerGracefully(): void
    {
        foreach ([SIGTERM, SIGINT] as $signal) {
            $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2')];
            $this->acked = [];
            $this->failingDispatch = static function () use ($signal): void {
                posix_kill(posix_getpid(), $signal);
            };
            $async = pcntl_async_signals();
            pcntl_async_signals(false); // run() itself must switch asynchronous delivery on

            try {
                $this->consumer()->run();
            } finally {
                pcntl_signal(SIGTERM, SIG_DFL);
                pcntl_signal(SIGINT, SIG_DFL);
                pcntl_async_signals($async);
            }

            self::assertSame([1], $this->acked, "signal {$signal} stops the loop after the current delivery");
        }
    }

    // success path

    public function testDispatchedDeliveryCommitsAcksAndRecordsTheEvent(): void
    {
        $this->pending = [$this->delivery(5, 'm-5')];

        $this->consumer()->run(messageLimit: 1);

        self::assertSame(['begin', 'commit'], $this->transactionCalls);
        self::assertSame([5], $this->acked);
        self::assertCount(1, $this->dispatched);
        self::assertSame([[
            'event' => BrokerEvents::DISPATCHED,
            'context' => [
                'consumer' => 'orders_consumer',
                'message_id' => 'm-5',
                'message_name' => 'order.placed',
            ],
        ]], $this->events->records);
    }

    public function testDuplicateCommitsAcksSkipsDispatchAndRecordsTheEvent(): void
    {
        $this->duplicate = true;
        $this->pending = [$this->delivery(6, 'm-6')];

        $this->consumer()->run(messageLimit: 1);

        self::assertSame(['begin', 'commit'], $this->transactionCalls);
        self::assertSame([6], $this->acked);
        self::assertSame([], $this->dispatched);
        self::assertSame([[
            'event' => BrokerEvents::DEDUPLICATED,
            'context' => [
                'consumer' => 'orders_consumer',
                'message_id' => 'm-6',
                'message_name' => 'order.placed',
            ],
        ]], $this->events->records);
    }

    public function testWorksWithoutEventsAndErrorHandler(): void
    {
        $this->pending = [$this->delivery()];

        $this->consumer(withObservers: false)->run(messageLimit: 1);

        self::assertSame([1], $this->acked);
        self::assertSame([], $this->events->records);
    }

    // dispatch failure: retry

    public function testFailedDispatchRollsBackRepublishesToAConfirmedWaitQueueAndAcks(): void
    {
        $this->failingDispatch = static fn () => throw new RuntimeException('handler failed');
        $this->pending = [$this->delivery(4, 'm-4', [
            'x-custom' => 'kept',
        ])];

        $this->consumer(confirmTimeoutSec: 9)->run(messageLimit: 1);

        self::assertSame(['begin', 'rollback'], $this->transactionCalls);
        self::assertSame(1, $this->confirmSelects);
        self::assertSame(1, $this->nackHandlers);

        self::assertCount(1, $this->declared);
        $declare = $this->declared[0];
        self::assertSame('orders.wait.100', $declare[0]);
        self::assertSame(false, $declare[1], 'passive');
        self::assertSame(true, $declare[2], 'durable');
        self::assertSame(false, $declare[3], 'exclusive');
        self::assertSame(false, $declare[4], 'auto-delete');
        self::assertSame(false, $declare[5], 'nowait');
        self::assertInstanceOf(AMQPTable::class, $declare[6]);
        self::assertSame([
            'x-message-ttl' => 100,
            'x-dead-letter-exchange' => '',
            'x-dead-letter-routing-key' => self::QUEUE,
        ], $declare[6]->getNativeData());

        self::assertCount(1, $this->published);
        $publish = $this->published[0];
        self::assertSame('', $publish['exchange']);
        self::assertSame('orders.wait.100', $publish['routingKey']);
        self::assertSame('{"order_id":"o-1"}', $publish['message']->getBody());
        $headers = $publish['message']->get('application_headers')->getNativeData();
        self::assertSame(2, $headers['x-attempt']);
        self::assertSame('kept', $headers['x-custom']);
        self::assertSame('m-4', $headers['x-message-id']);

        self::assertSame([9], $this->pendingAckTimeouts, 'the republish is confirmed before the ack');
        self::assertSame([4], $this->acked);
        self::assertSame([], $this->deadLetters);
    }

    public function testFailureReportsToTheLoggerAndTheErrorHandlerWithTheSameContext(): void
    {
        $error = new RuntimeException('handler failed');
        $this->failingDispatch = static fn () => throw $error;
        $this->pending = [$this->delivery(1, 'm-1', [
            'x-attempt' => 2,
        ])];

        $this->consumer()->run(messageLimit: 1);

        $expected = [
            'queue' => self::QUEUE,
            'message_id' => 'm-1',
            'message_name' => 'order.placed',
            'attempt' => 2,
            'action' => 'Retry',
        ];
        self::assertCount(1, $this->logger->records);
        $record = $this->logger->records[0];
        self::assertSame(LogLevel::WARNING, $record['level']);
        self::assertSame('Consumer dispatch failed', $record['message']);
        self::assertSame($error, $record['context']['exception']);
        unset($record['context']['exception']);
        self::assertSame($expected, $record['context']);

        self::assertCount(1, $this->errorHandler->calls);
        self::assertSame($error, $this->errorHandler->calls[0]['error']);
        self::assertSame($expected, $this->errorHandler->calls[0]['context']);
        self::assertSame(3, $this->published[0]['message']->get('application_headers')->getNativeData()['x-attempt']);
    }

    public function testConfirmModeAndWaitQueueAreSetUpOncePerDelay(): void
    {
        $this->failingDispatch = static fn () => throw new RuntimeException('handler failed');
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2')];

        $this->consumer()->run(messageLimit: 2);

        self::assertSame(1, $this->confirmSelects);
        self::assertSame(1, $this->nackHandlers);
        self::assertCount(1, $this->declared, 'the wait queue of one delay is declared once');
        self::assertCount(2, $this->published);
    }

    public function testBrokerNackOfTheRepublishStopsTheConsumerWithoutAck(): void
    {
        $this->failingDispatch = static fn () => throw new RuntimeException('handler failed');
        $this->nackOnConfirm = true;
        $this->pending = [$this->delivery(1, 'm-1')];

        try {
            $this->consumer()->run(messageLimit: 1);
            self::fail('a nacked republish must stop the consumer');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('orders.wait.100', $e->getMessage());
        }

        self::assertSame([], $this->acked, 'the original delivery stays unacked');
    }

    // dispatch failure: dead letter

    public function testExhaustedRetriesStoreTheCanonicalDocumentAsDeadLetter(): void
    {
        $this->failingDispatch = static fn () => throw new RuntimeException('final failure');
        $this->pending = [$this->delivery(1, 'm-9', [
            'x-attempt' => 3,
            'x-custom' => 'h',
        ])];

        $this->consumer(maxAttempts: 3)->run(messageLimit: 1);

        self::assertSame([], $this->published);
        self::assertSame([1], $this->acked);
        self::assertCount(1, $this->deadLetters);
        $letter = $this->deadLetters[0];
        self::assertSame(self::QUEUE, $letter->source);
        self::assertSame('m-9', $letter->messageId);
        self::assertSame('order.placed', $letter->messageName);
        self::assertSame(3, $letter->attempts);
        self::assertSame(RuntimeException::class, $letter->errorClass);
        self::assertSame('final failure', $letter->errorMessage);
        self::assertSame('h', $letter->headers['x-custom']);
        self::assertSame([
            'metadata' => [
                'message_name' => 'order.placed',
                'message_id' => 'm-9',
                'created_at' => 1_765_476_000_123,
            ],
            'payload' => [
                'order_id' => 'o-1',
            ],
        ], json_decode($letter->body, true));

        self::assertSame([[
            'event' => BrokerEvents::DEAD_LETTERED,
            'context' => [
                'source' => self::QUEUE,
                'message_id' => 'm-9',
                'message_name' => 'order.placed',
            ],
        ]], $this->events->records);
    }

    public function testDeadLetterDocumentSubstitutesInvalidUtf8InsteadOfFailing(): void
    {
        $this->failingDispatch = static fn () => throw new RuntimeException('final failure');
        $this->pending = [$this->delivery(1, 'm-9', [
            'x-attempt' => 3,
        ], body: "{\"note\":\"caf\xE9\"}")];
        $deserializer = new class() implements Deserializer {
            public function deserialize(string $bytes, array $headers = []): IncomingMessage
            {
                return new IncomingMessage('m-9', 'order.placed', 1, [
                    'note' => "caf\xE9",
                ], $headers);
            }
        };

        $this->consumer(deserializer: $deserializer, maxAttempts: 3)->run(messageLimit: 1);

        self::assertCount(1, $this->deadLetters);
        $document = json_decode($this->deadLetters[0]->body, true);
        self::assertSame("caf\u{FFFD}", $document['payload']['note']);
        self::assertSame([1], $this->acked);
    }

    // malformed

    public function testMalformedDeliveryIsDeadLetteredWithEnvelopeFieldsAndAcked(): void
    {
        $this->pending = [$this->delivery(2, 'm-bad', body: '{{{not json')];

        $this->consumer()->run(messageLimit: 1);

        self::assertSame([2], $this->acked);
        self::assertSame([], $this->transactionCalls, 'a malformed message never opens a transaction');
        self::assertCount(1, $this->deadLetters);
        $letter = $this->deadLetters[0];
        self::assertSame(self::QUEUE, $letter->source);
        self::assertSame('m-bad', $letter->messageId);
        self::assertSame('order.placed', $letter->messageName);
        self::assertSame('{{{not json', $letter->body);
        self::assertSame(1, $letter->attempts, 'a delivery without x-attempt is attempt 1');
        self::assertSame([[
            'event' => BrokerEvents::DEAD_LETTERED,
            'context' => [
                'source' => self::QUEUE,
                'message_id' => 'm-bad',
                'message_name' => 'order.placed',
            ],
        ]], $this->events->records);
    }

    public function testMalformedDeliveryWithoutEnvelopeFallsBackToThePropertyMessageId(): void
    {
        $this->pending = [$this->delivery(2, withEnvelope: false, propertyMessageId: 'from-property')];

        $this->consumer()->run(messageLimit: 1);

        self::assertSame('from-property', $this->deadLetters[0]->messageId);
        self::assertSame('unknown', $this->deadLetters[0]->messageName);
    }

    public function testMalformedDeliveryWithoutAnyIdUsesUnknown(): void
    {
        $this->pending = [$this->delivery(2, withEnvelope: false)];

        $this->consumer()->run(messageLimit: 1);

        self::assertSame('unknown', $this->deadLetters[0]->messageId);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidAttemptHeaders(): iterable
    {
        yield 'string' => ['3'];
        yield 'zero' => [0];
        yield 'negative' => [-4];
        yield 'float' => [2.0];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidAttemptHeaders')]
    public function testAnInvalidAttemptHeaderCountsAsTheFirstAttempt(mixed $header): void
    {
        $this->pending = [$this->delivery(2, 'm-bad', [
            'x-attempt' => $header,
        ], body: '{{{not json')];

        $this->consumer()->run(messageLimit: 1);

        self::assertSame(1, $this->deadLetters[0]->attempts);
    }

    public function testTheAttemptHeaderOfAMalformedDeliveryIsKept(): void
    {
        $this->pending = [$this->delivery(2, 'm-bad', [
            'x-attempt' => 4,
        ], body: '{{{not json')];

        $this->consumer()->run(messageLimit: 1);

        self::assertSame(4, $this->deadLetters[0]->attempts);
    }

    // transient deserialize failure

    public function testTransientDeserializeFailureIsReportedThenRethrownWithoutAck(): void
    {
        $error = new RuntimeException('registry down');
        $deserializer = new class($error) implements Deserializer {
            public function __construct(
                private readonly RuntimeException $error,
            ) {}

            public function deserialize(string $bytes, array $headers = []): IncomingMessage
            {
                throw $this->error;
            }
        };
        $this->pending = [$this->delivery(1, 'm-1', [
            'x-attempt' => 2,
        ], propertyMessageId: 'prop-id')];

        try {
            $this->consumer(deserializer: $deserializer)->run(messageLimit: 1);
            self::fail('a transient failure must propagate');
        } catch (RuntimeException $e) {
            self::assertSame($error, $e);
        }

        $expected = [
            'queue' => self::QUEUE,
            'stage' => 'deserialize',
            'message_id' => 'prop-id',
            'attempt' => 2,
        ];
        self::assertCount(1, $this->logger->records);
        $record = $this->logger->records[0];
        self::assertSame(LogLevel::ERROR, $record['level']);
        self::assertSame('Consumer deserialize failed (transient); requeueing', $record['message']);
        self::assertSame($error, $record['context']['exception']);
        unset($record['context']['exception']);
        self::assertSame($expected, $record['context']);
        self::assertCount(1, $this->errorHandler->calls);
        self::assertSame($error, $this->errorHandler->calls[0]['error']);
        self::assertSame($expected, $this->errorHandler->calls[0]['context']);
        self::assertSame([], $this->acked);
        self::assertSame([], $this->deadLetters);
        self::assertSame([], $this->transactionCalls);
    }

    public function testTransientDeserializeFailureWithoutAnErrorHandlerStillRethrowsTheOriginalError(): void
    {
        $error = new RuntimeException('registry down');
        $deserializer = new class($error) implements Deserializer {
            public function __construct(
                private readonly RuntimeException $error,
            ) {}

            public function deserialize(string $bytes, array $headers = []): IncomingMessage
            {
                throw $this->error;
            }
        };
        $this->pending = [$this->delivery()];

        try {
            $this->consumer(deserializer: $deserializer, withObservers: false)->run(messageLimit: 1);
            self::fail('a transient failure must propagate');
        } catch (\Throwable $e) {
            self::assertSame($error, $e);
        }

        self::assertCount(1, $this->logger->records);
    }

    public function testTransientDeserializeFailureWithoutPropertyIdReportsUnknown(): void
    {
        $deserializer = new class() implements Deserializer {
            public function deserialize(string $bytes, array $headers = []): IncomingMessage
            {
                throw new RuntimeException('registry down');
            }
        };
        $this->pending = [$this->delivery(1)];

        try {
            $this->consumer(deserializer: $deserializer)->run(messageLimit: 1);
            self::fail('a transient failure must propagate');
        } catch (RuntimeException) {
        }

        self::assertSame('unknown', $this->errorHandler->calls[0]['context']['message_id']);
        self::assertSame(1, $this->errorHandler->calls[0]['context']['attempt']);
    }

    // database failure

    public function testFailureToBeginReportsTheDatabaseFailureAndStops(): void
    {
        $error = new PDOException('db down');
        $this->beginFails = static fn (): PDOException => $error;
        $this->pending = [$this->delivery(1, 'm-1', [
            'x-attempt' => 2,
        ])];

        try {
            $this->consumer()->run(messageLimit: 1);
            self::fail('a database failure must stop the consumer');
        } catch (PDOException $e) {
            self::assertSame($error, $e);
        }

        $expected = [
            'queue' => self::QUEUE,
            'stage' => 'transaction',
            'message_id' => 'm-1',
            'attempt' => 2,
        ];
        self::assertCount(1, $this->logger->records);
        $record = $this->logger->records[0];
        self::assertSame(LogLevel::ERROR, $record['level']);
        self::assertStringContainsString('database failure', $record['message']);
        self::assertSame($error, $record['context']['exception']);
        unset($record['context']['exception']);
        self::assertSame($expected, $record['context']);
        self::assertCount(1, $this->errorHandler->calls);
        self::assertSame($error, $this->errorHandler->calls[0]['error']);
        self::assertSame($expected, $this->errorHandler->calls[0]['context']);
        self::assertSame([], $this->acked);
    }

    public function testFailedRollbackIsADatabaseFailureNotAHandlerFailure(): void
    {
        $error = new RuntimeException('handler failed');
        $this->failingDispatch = static fn () => throw $error;
        $this->rollbackFails = true;
        $this->pending = [$this->delivery(1, 'm-1')];

        try {
            $this->consumer()->run(messageLimit: 1);
            self::fail('a failed rollback must stop the consumer');
        } catch (RuntimeException $e) {
            self::assertSame($error, $e, 'the real handler error propagates');
        }

        self::assertSame([], $this->acked);
        self::assertSame([], $this->published, 'no retry is spent');
        self::assertSame([], $this->deadLetters);
        self::assertSame('transaction', $this->errorHandler->calls[0]['context']['stage']);
    }
}
