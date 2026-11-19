<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Consumer;

use Freyr\MessageBroker\Consumer\CallableDispatcher;
use Freyr\MessageBroker\Consumer\Delivery;
use Freyr\MessageBroker\Consumer\DeliveryLimitExceeded;
use Freyr\MessageBroker\Consumer\DeliveryProcessor;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Retry\ExponentialRetryPolicy;
use Freyr\MessageBroker\Retry\RetryDecision;
use Freyr\MessageBroker\Retry\RetryPolicy;
use Freyr\MessageBroker\Serializer\Deserializer;
use Freyr\MessageBroker\Serializer\JsonDeserializer;
use Freyr\MessageBroker\Serializer\MalformedMessage;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use Freyr\MessageBroker\Testing\InMemoryDeadLetterStore;
use Freyr\MessageBroker\Testing\RecordingDeliveryControl;
use Freyr\MessageBroker\Testing\RecordingErrorHandler;
use Freyr\MessageBroker\Testing\RecordingEvents;
use Freyr\MessageBroker\Testing\RecordingLogger;
use Freyr\MessageBroker\Testing\RecordingTransaction;
use Freyr\MessageBroker\Testing\StubDeduplicationStore;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Throwable;

/**
 * The exactly-once algorithm against fakes: no AMQP class, no database.
 * Every outward effect (transaction calls, outcome calls, logs, events, dead
 * letters) is recorded and asserted.
 */
final class DeliveryProcessorTest extends TestCase
{
    private const string SOURCE = 'orders';

    /** @var list<IncomingMessage> */
    private array $dispatched = [];

    private ?\Closure $failingDispatch = null;

    private RecordingLogger $logger;

    private RecordingEvents $events;

    private RecordingErrorHandler $errorHandler;

    private RecordingTransaction $transaction;

    private StubDeduplicationStore $dedup;

    private RecordingDeliveryControl $control;

    private InMemoryDeadLetterStore $deadLetterStore;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->events = new RecordingEvents();
        $this->errorHandler = new RecordingErrorHandler();
        $this->transaction = new RecordingTransaction();
        $this->dedup = new StubDeduplicationStore();
        $this->control = new RecordingDeliveryControl();
        $this->deadLetterStore = new InMemoryDeadLetterStore();
    }

    private function processor(
        ?Deserializer $deserializer = null,
        int $maxAttempts = 3,
        bool $withObservers = true,
        ?RetryPolicy $retryPolicy = null,
        ?int $maxDeliveries = 5,
        ?DeadLetterStore $deadLetters = null,
    ): DeliveryProcessor {
        return new DeliveryProcessor(
            deserializer: $deserializer ?? new JsonDeserializer(),
            dispatcher: new CallableDispatcher(function (IncomingMessage $incoming): void {
                if ($this->failingDispatch !== null) {
                    ($this->failingDispatch)($incoming);
                }
                $this->dispatched[] = $incoming;
            }),
            transaction: $this->transaction,
            deduplication: $this->dedup,
            retryPolicy: $retryPolicy ?? new ExponentialRetryPolicy(
                maxAttempts: $maxAttempts,
                backoff: Backoff::exponential(initialDelayMs: 100, maxDelayMs: 100),
            ),
            deadLetters: $deadLetters ?? $this->deadLetterStore,
            name: 'orders_consumer',
            errorHandler: $withObservers ? $this->errorHandler : null,
            logger: $this->logger,
            events: $withObservers ? $this->events : null,
            maxDeliveries: $maxDeliveries,
        );
    }

    /** @param array<string, mixed> $extraHeaders */
    private function delivery(
        string $messageId = 'm-1',
        array $extraHeaders = [],
        string $body = '{"order_id":"o-1"}',
        bool $withEnvelope = true,
        ?string $fallbackMessageId = null,
        int $attempt = 1,
        ?int $deliveryCount = null,
    ): Delivery {
        $headers = ($withEnvelope ? MetadataHeader::envelope($messageId, 'order.placed', 1_765_476_000_123) : [])
            + $extraHeaders;

        return new Delivery($body, $headers, $attempt, self::SOURCE, $fallbackMessageId, $deliveryCount);
    }

    /** @return list<DeadLetter> */
    private function deadLetters(): array
    {
        return $this->deadLetterStore->list();
    }

    /** The id of a raw dead letter with no message id: 'unknown-' and a UUID v7. */
    private static function assertGeneratedUnknownId(string $messageId): void
    {
        self::assertStringStartsWith('unknown-', $messageId);
        $uuid = substr($messageId, strlen('unknown-'));
        self::assertTrue(Uuid::isValid($uuid), "not a UUID: {$uuid}");
        self::assertInstanceOf(UuidV7::class, Uuid::fromString($uuid));
        self::assertSame(Uuid::fromString($uuid)->toRfc4122(), $uuid, 'the canonical lower-case form');
    }

    private static function failure(string $message = 'handler failed'): \Closure
    {
        return static fn () => throw new RuntimeException($message);
    }

    private static function throwingDeserializer(Throwable $error): Deserializer
    {
        return new class($error) implements Deserializer {
            public function __construct(
                private readonly Throwable $error,
            ) {}

            public function deserialize(string $bytes, array $headers = []): IncomingMessage
            {
                throw $this->error;
            }
        };
    }

    // success path

    public function testDispatchedDeliveryCommitsAcksAndRecordsTheEvent(): void
    {
        $this->processor()->process($this->delivery('m-5'), $this->control);

        self::assertSame(['begin', 'commit'], $this->transaction->calls);
        self::assertSame(['ack'], $this->control->calls);
        self::assertCount(1, $this->dispatched);
        self::assertSame(['orders_consumer'], $this->dedup->scopes);
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
        $this->dedup->duplicate = true;

        $this->processor()->process($this->delivery('m-6'), $this->control);

        self::assertSame(['begin', 'commit'], $this->transaction->calls);
        self::assertSame(['ack'], $this->control->calls);
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
        $this->processor(withObservers: false)->process($this->delivery(), $this->control);

        self::assertSame(['ack'], $this->control->calls);
        self::assertSame([], $this->events->records);
    }

    public function testTheDefaultDedupScopeIsDefault(): void
    {
        $processor = new DeliveryProcessor(
            deserializer: new JsonDeserializer(),
            dispatcher: new CallableDispatcher(static function (IncomingMessage $incoming): void {}),
            transaction: $this->transaction,
            deduplication: $this->dedup,
            retryPolicy: new ExponentialRetryPolicy(),
            deadLetters: $this->deadLetterStore,
        );

        $processor->process($this->delivery(), $this->control);

        self::assertSame(['default'], $this->dedup->scopes);
    }

    // dispatch failure: retry

    public function testFailedDispatchRollsBackSchedulesTheRetryAndAcksAfterwards(): void
    {
        $this->failingDispatch = self::failure();

        $this->processor()->process($this->delivery('m-4'), $this->control);

        self::assertSame(['begin', 'rollback'], $this->transaction->calls);
        self::assertSame(['retry:100:2', 'ack'], $this->control->calls, 'the retry is scheduled before the ack');
        self::assertSame([], $this->deadLetters());
    }

    public function testAConfirmedRetryRecordsTheRetriedEventWithEachKey(): void
    {
        $this->failingDispatch = self::failure();

        $this->processor()->process($this->delivery('m-4', attempt: 2), $this->control);

        self::assertSame([[
            'event' => BrokerEvents::RETRIED,
            'context' => [
                'consumer' => 'orders_consumer',
                'source' => self::SOURCE,
                'message_id' => 'm-4',
                'message_name' => 'order.placed',
                'attempt' => 2,
                'next_attempt' => 3,
                'delay_ms' => 100,
                'error_class' => RuntimeException::class,
            ],
        ]], $this->events->records);
    }

    public function testFailureReportsToTheLoggerAndTheErrorHandlerWithTheSameContext(): void
    {
        $error = new RuntimeException('handler failed');
        $this->failingDispatch = static fn () => throw $error;

        $this->processor()->process($this->delivery('m-1', attempt: 2), $this->control);

        $expected = [
            'queue' => self::SOURCE,
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
        self::assertSame(['retry:100:3', 'ack'], $this->control->calls);
    }

    public function testARefusedRetryThrowsBeforeTheAckAndBeforeTheReport(): void
    {
        $this->failingDispatch = self::failure();
        $this->control->retryFails = true;

        try {
            $this->processor()->process($this->delivery(), $this->control);
            self::fail('a refused retry must stop the processor');
        } catch (RuntimeException $e) {
            self::assertSame('retry refused', $e->getMessage());
        }

        self::assertSame([], $this->control->calls, 'the delivery stays unacked');
        self::assertSame([], $this->events->records, 'no RETRIED event when the transport refused the retry');
        self::assertSame([], $this->logger->records);
        self::assertSame([], $this->errorHandler->calls);
    }

    public function testThePolicyReceivesTheAttemptTheErrorAndTheMessage(): void
    {
        $error = new RuntimeException('handler failed');
        $this->failingDispatch = static fn () => throw $error;
        $policy = new class() implements RetryPolicy {
            /** @var list<array{int, Throwable, IncomingMessage}> */
            public array $calls = [];

            public function decide(int $attempt, Throwable $error, IncomingMessage $message): RetryDecision
            {
                $this->calls[] = [$attempt, $error, $message];

                return RetryDecision::retryIn(7);
            }
        };

        $this->processor(retryPolicy: $policy)->process($this->delivery('m-3', attempt: 4), $this->control);

        self::assertCount(1, $policy->calls);
        self::assertSame(4, $policy->calls[0][0]);
        self::assertSame($error, $policy->calls[0][1]);
        self::assertSame('m-3', $policy->calls[0][2]->messageId);
        self::assertSame(['retry:7:5', 'ack'], $this->control->calls);
    }

    // dispatch failure: dead letter and discard

    public function testExhaustedRetriesStoreTheCanonicalDocumentAsDeadLetter(): void
    {
        $this->failingDispatch = self::failure('final failure');

        $this->processor(maxAttempts: 3)->process(
            $this->delivery('m-9', [
                'x-custom' => 'h',
            ], attempt: 3),
            $this->control,
        );

        self::assertSame(['ack'], $this->control->calls);
        self::assertCount(1, $this->deadLetters());
        $letter = $this->deadLetters()[0];
        self::assertSame(self::SOURCE, $letter->source);
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
                'source' => self::SOURCE,
                'message_id' => 'm-9',
                'message_name' => 'order.placed',
            ],
        ]], $this->events->records);
        self::assertSame('DeadLetter', $this->errorHandler->calls[0]['context']['action']);
    }

    public function testTheDeadLetteredEventOfAFailedHandlerCarriesTheIdOfTheStoredRow(): void
    {
        $this->failingDispatch = self::failure('final failure');

        $this->processor(maxAttempts: 3)->process($this->delivery(str_repeat('m', 300), attempt: 3), $this->control);

        $storedId = str_repeat('m', 255);
        self::assertSame($storedId, $this->deadLetters()[0]->messageId);
        self::assertSame([[
            'event' => BrokerEvents::DEAD_LETTERED,
            'context' => [
                'source' => self::SOURCE,
                'message_id' => $storedId,
                'message_name' => 'order.placed',
            ],
        ]], $this->events->records);
    }

    public function testDeadLetterDocumentSubstitutesInvalidUtf8InsteadOfFailing(): void
    {
        $this->failingDispatch = self::failure('final failure');
        $deserializer = new class() implements Deserializer {
            public function deserialize(string $bytes, array $headers = []): IncomingMessage
            {
                return new IncomingMessage('m-9', 'order.placed', 1, [
                    'note' => "caf\xE9",
                ], $headers);
            }
        };

        $this->processor(deserializer: $deserializer, maxAttempts: 3)->process(
            $this->delivery('m-9', body: "{\"note\":\"caf\xE9\"}", attempt: 3),
            $this->control,
        );

        self::assertCount(1, $this->deadLetters());
        $document = json_decode($this->deadLetters()[0]->body, true);
        self::assertSame("caf\u{FFFD}", $document['payload']['note']);
        self::assertSame(['ack'], $this->control->calls);
    }

    public function testADiscardDecisionAcksWithoutRetryOrDeadLetter(): void
    {
        $this->failingDispatch = self::failure();
        $policy = new class() implements RetryPolicy {
            public function decide(int $attempt, Throwable $error, IncomingMessage $message): RetryDecision
            {
                return RetryDecision::discard();
            }
        };

        $this->processor(retryPolicy: $policy)->process($this->delivery(), $this->control);

        self::assertSame(['ack'], $this->control->calls);
        self::assertSame([], $this->deadLetters());
        self::assertSame([[
            'event' => BrokerEvents::DISCARDED,
            'context' => [
                'consumer' => 'orders_consumer',
                'source' => self::SOURCE,
                'message_id' => 'm-1',
                'message_name' => 'order.placed',
                'attempt' => 1,
                'error_class' => RuntimeException::class,
            ],
        ]], $this->events->records);
        self::assertSame('Discard', $this->errorHandler->calls[0]['context']['action']);
    }

    // malformed

    public function testMalformedDeliveryIsDeadLetteredWithEnvelopeFieldsAndAcked(): void
    {
        $this->processor()->process($this->delivery('m-bad', body: '{{{not json'), $this->control);

        self::assertSame(['ack'], $this->control->calls);
        self::assertSame([], $this->transaction->calls, 'a malformed message never opens a transaction');
        self::assertCount(1, $this->deadLetters());
        $letter = $this->deadLetters()[0];
        self::assertSame(self::SOURCE, $letter->source);
        self::assertSame('m-bad', $letter->messageId);
        self::assertSame('order.placed', $letter->messageName);
        self::assertSame('{{{not json', $letter->body);
        self::assertSame(1, $letter->attempts);
        self::assertSame([[
            'event' => BrokerEvents::DEAD_LETTERED,
            'context' => [
                'source' => self::SOURCE,
                'message_id' => 'm-bad',
                'message_name' => 'order.placed',
            ],
        ]], $this->events->records);
    }

    public function testMalformedDeliveryWithoutEnvelopeFallsBackToTheTransportMessageId(): void
    {
        $this->processor()->process(
            $this->delivery(withEnvelope: false, fallbackMessageId: 'from-property'),
            $this->control,
        );

        self::assertSame('from-property', $this->deadLetters()[0]->messageId);
        self::assertSame('unknown', $this->deadLetters()[0]->messageName);
    }

    public function testMalformedDeliveryWithoutAnyIdUsesUnknown(): void
    {
        $this->processor()->process($this->delivery(withEnvelope: false), $this->control);

        self::assertCount(1, $this->deadLetters());
        $messageId = $this->deadLetters()[0]->messageId;
        self::assertGeneratedUnknownId($messageId);
        self::assertSame('unknown', $this->deadLetters()[0]->messageName);
        self::assertSame($messageId, $this->events->records[0]['context']['message_id'], 'the event carries the stored id');
    }

    public function testTwoMalformedDeliveriesWithoutAnyIdGiveTwoRowsWithDifferentIds(): void
    {
        $processor = $this->processor();

        $processor->process($this->delivery(withEnvelope: false), $this->control);
        $processor->process($this->delivery(withEnvelope: false), $this->control);

        self::assertCount(2, $this->deadLetters());
        [$first, $second] = $this->deadLetters();
        self::assertGeneratedUnknownId($first->messageId);
        self::assertGeneratedUnknownId($second->messageId);
        self::assertNotSame($first->messageId, $second->messageId);
    }

    public function testTheAttemptOfAMalformedDeliveryIsKept(): void
    {
        $this->processor()->process($this->delivery('m-bad', body: '{{{not json', attempt: 4), $this->control);

        self::assertSame(4, $this->deadLetters()[0]->attempts);
    }

    // transient deserialize failure

    public function testTransientDeserializeFailureIsReportedThenRethrownWithoutAck(): void
    {
        $error = new RuntimeException('registry down');

        try {
            $this->processor(deserializer: self::throwingDeserializer($error))->process(
                $this->delivery('m-1', attempt: 2, fallbackMessageId: 'prop-id'),
                $this->control,
            );
            self::fail('a transient failure must propagate');
        } catch (RuntimeException $e) {
            self::assertSame($error, $e);
        }

        $expected = [
            'queue' => self::SOURCE,
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
        self::assertSame([], $this->control->calls);
        self::assertSame([], $this->deadLetters());
        self::assertSame([], $this->transaction->calls);
    }

    public function testTransientDeserializeFailureWithoutAnErrorHandlerStillRethrowsTheOriginalError(): void
    {
        $error = new RuntimeException('registry down');

        try {
            $this->processor(deserializer: self::throwingDeserializer($error), withObservers: false)->process(
                $this->delivery(),
                $this->control,
            );
            self::fail('a transient failure must propagate');
        } catch (Throwable $e) {
            self::assertSame($error, $e);
        }

        self::assertCount(1, $this->logger->records);
    }

    public function testTransientDeserializeFailureWithoutTransportIdReportsUnknown(): void
    {
        try {
            $this->processor(deserializer: self::throwingDeserializer(new RuntimeException('registry down')))->process(
                $this->delivery(),
                $this->control,
            );
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
        $this->transaction->beginFails = $error;

        try {
            $this->processor()->process($this->delivery('m-1', attempt: 2), $this->control);
            self::fail('a database failure must stop the processor');
        } catch (PDOException $e) {
            self::assertSame($error, $e);
        }

        $expected = [
            'queue' => self::SOURCE,
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
        self::assertSame([], $this->control->calls);
        self::assertSame(['begin'], $this->transaction->calls);
    }

    public function testFailedRollbackIsADatabaseFailureNotAHandlerFailure(): void
    {
        $error = new RuntimeException('handler failed');
        $this->failingDispatch = static fn () => throw $error;
        $this->transaction->rollBackFails = new PDOException('connection gone');

        try {
            $this->processor()->process($this->delivery(), $this->control);
            self::fail('a failed rollback must stop the processor');
        } catch (RuntimeException $e) {
            self::assertSame($error, $e, 'the real handler error propagates');
        }

        self::assertSame([], $this->control->calls, 'no ack and no retry is spent');
        self::assertSame([], $this->deadLetters());
        self::assertSame('transaction', $this->errorHandler->calls[0]['context']['stage']);
    }

    // delivery limit

    public function testACountEqualToTheLimitDeadLettersWithNoTransactionNoDeduplicationAndNoDispatchThenAcks(): void
    {
        $this->processor(maxDeliveries: 3)->process(
            $this->delivery('m-7', [
                'x-custom' => 'h',
            ], attempt: 2, deliveryCount: 3),
            $this->control,
        );

        self::assertSame([], $this->transaction->calls, 'no BEGIN');
        self::assertSame([], $this->dedup->scopes, 'no deduplication acquire');
        self::assertSame([], $this->dispatched, 'no dispatch');
        self::assertSame(['ack'], $this->control->calls);
        self::assertCount(1, $this->deadLetters());
        $letter = $this->deadLetters()[0];
        self::assertSame(self::SOURCE, $letter->source);
        self::assertSame('m-7', $letter->messageId);
        self::assertSame('order.placed', $letter->messageName);
        self::assertSame(DeliveryLimitExceeded::class, $letter->errorClass);
        self::assertSame('Delivery limit reached: 3 deliveries ended with no ack (limit 3)', $letter->errorMessage);
        self::assertSame(2, $letter->attempts, 'attempts is the attempt of the delivery, never the count');
        self::assertSame('h', $letter->headers['x-custom']);
        self::assertSame([
            'metadata' => [
                'message_name' => 'order.placed',
                'message_id' => 'm-7',
                'created_at' => 1_765_476_000_123,
            ],
            'payload' => [
                'order_id' => 'o-1',
            ],
        ], json_decode($letter->body, true), 'the canonical document');
        self::assertSame([[
            'event' => BrokerEvents::DEAD_LETTERED,
            'context' => [
                'source' => self::SOURCE,
                'message_id' => 'm-7',
                'message_name' => 'order.placed',
            ],
        ]], $this->events->records);
    }

    public function testACountAboveTheLimitDeadLetters(): void
    {
        $this->processor(maxDeliveries: 3)->process($this->delivery(deliveryCount: 4), $this->control);

        self::assertSame(['ack'], $this->control->calls);
        self::assertSame([], $this->dispatched);
        self::assertSame(
            'Delivery limit reached: 4 deliveries ended with no ack (limit 3)',
            $this->deadLetters()[0]->errorMessage,
        );
    }

    public function testACountOneBelowTheLimitTakesTheNormalPath(): void
    {
        $this->processor(maxDeliveries: 3)->process($this->delivery(deliveryCount: 2), $this->control);

        self::assertSame(['begin', 'commit'], $this->transaction->calls);
        self::assertCount(1, $this->dispatched);
        self::assertSame(['ack'], $this->control->calls);
        self::assertSame([], $this->deadLetters());
    }

    public function testNoCountTakesTheNormalPath(): void
    {
        $this->processor(maxDeliveries: 1)->process($this->delivery(deliveryCount: null), $this->control);

        self::assertSame(['begin', 'commit'], $this->transaction->calls);
        self::assertCount(1, $this->dispatched);
        self::assertSame([], $this->deadLetters());
    }

    public function testNoLimitTakesTheNormalPathAtAnyCount(): void
    {
        $this->processor(maxDeliveries: null)->process($this->delivery(deliveryCount: 100), $this->control);

        self::assertSame(['begin', 'commit'], $this->transaction->calls);
        self::assertCount(1, $this->dispatched);
        self::assertSame([], $this->deadLetters());
    }

    public function testTheDefaultLimitIsFive(): void
    {
        $processor = new DeliveryProcessor(
            deserializer: new JsonDeserializer(),
            dispatcher: new CallableDispatcher(function (IncomingMessage $incoming): void {
                $this->dispatched[] = $incoming;
            }),
            transaction: $this->transaction,
            deduplication: $this->dedup,
            retryPolicy: new ExponentialRetryPolicy(),
            deadLetters: $this->deadLetterStore,
        );

        $processor->process($this->delivery('m-4', deliveryCount: 4), $this->control);
        $processor->process($this->delivery('m-5', deliveryCount: 5), $this->control);

        self::assertCount(1, $this->dispatched);
        self::assertSame('m-4', $this->dispatched[0]->messageId);
        self::assertCount(1, $this->deadLetters());
        self::assertSame('m-5', $this->deadLetters()[0]->messageId);
        self::assertSame(
            'Delivery limit reached: 5 deliveries ended with no ack (limit 5)',
            $this->deadLetters()[0]->errorMessage,
        );
    }

    public function testAMalformedDeliveryAtTheLimitStoresTheRawBytes(): void
    {
        $this->processor(maxDeliveries: 2)->process(
            $this->delivery('m-bad', [
                'x-custom' => 'h',
            ], body: '{{{not json', attempt: 3, deliveryCount: 2),
            $this->control,
        );

        self::assertSame(['ack'], $this->control->calls);
        self::assertSame([], $this->transaction->calls);
        self::assertCount(1, $this->deadLetters());
        $letter = $this->deadLetters()[0];
        self::assertSame('{{{not json', $letter->body);
        self::assertSame('m-bad', $letter->messageId);
        self::assertSame('order.placed', $letter->messageName);
        self::assertSame('h', $letter->headers['x-custom']);
        self::assertSame(DeliveryLimitExceeded::class, $letter->errorClass);
        self::assertSame('Delivery limit reached: 2 deliveries ended with no ack (limit 2)', $letter->errorMessage);
        self::assertSame(3, $letter->attempts);
    }

    /** @return iterable<string, array{Throwable}> */
    public static function deserializeFailures(): iterable
    {
        yield 'malformed' => [new MalformedMessage('bad bytes')];
        yield 'other throwable' => [new RuntimeException('registry down')];
    }

    #[DataProvider('deserializeFailures')]
    public function testADeserializeFailureAtTheLimitStoresTheRawDeliveryAndReportsOnlyTheLimit(Throwable $failure): void
    {
        $this->processor(deserializer: self::throwingDeserializer($failure), maxDeliveries: 2)->process(
            $this->delivery(withEnvelope: false, body: 'raw-bytes', fallbackMessageId: 'prop-id', deliveryCount: 2),
            $this->control,
        );

        self::assertSame(['ack'], $this->control->calls);
        self::assertCount(1, $this->deadLetters());
        $letter = $this->deadLetters()[0];
        self::assertSame('raw-bytes', $letter->body);
        self::assertSame([], $letter->headers);
        self::assertSame('prop-id', $letter->messageId);
        self::assertSame('unknown', $letter->messageName);
        self::assertSame(DeliveryLimitExceeded::class, $letter->errorClass);
        self::assertCount(1, $this->logger->records, 'only the limit warning, no deserialize error');
        self::assertSame('prop-id', $this->logger->records[0]['context']['message_id']);
        self::assertCount(1, $this->errorHandler->calls);
        self::assertInstanceOf(DeliveryLimitExceeded::class, $this->errorHandler->calls[0]['error']);
    }

    public function testARawDeadLetterAtTheLimitWithoutAnyIdUsesAGeneratedUnknownId(): void
    {
        $this->processor(maxDeliveries: 2)->process(
            $this->delivery(withEnvelope: false, body: '{{{not json', deliveryCount: 2),
            $this->control,
        );

        self::assertSame(['ack'], $this->control->calls);
        self::assertCount(1, $this->deadLetters());
        $messageId = $this->deadLetters()[0]->messageId;
        self::assertGeneratedUnknownId($messageId);
        self::assertSame($messageId, $this->logger->records[0]['context']['message_id'], 'the log names the stored id');
        self::assertSame($messageId, $this->errorHandler->calls[0]['context']['message_id']);
    }

    public function testARawDeadLetterAtTheLimitReportsTheIdOfTheStoredRow(): void
    {
        $this->processor(maxDeliveries: 2)->process(
            $this->delivery(
                withEnvelope: false,
                body: '{{{not json',
                fallbackMessageId: str_repeat('p', 300),
                deliveryCount: 2,
            ),
            $this->control,
        );

        $storedId = str_repeat('p', 255);
        self::assertSame($storedId, $this->deadLetters()[0]->messageId);
        self::assertSame($storedId, $this->logger->records[0]['context']['message_id']);
        self::assertSame($storedId, $this->errorHandler->calls[0]['context']['message_id']);
        self::assertSame([[
            'event' => BrokerEvents::DEAD_LETTERED,
            'context' => [
                'source' => self::SOURCE,
                'message_id' => $storedId,
                'message_name' => 'unknown',
            ],
        ]], $this->events->records);
    }

    public function testACanonicalDeadLetterAtTheLimitReportsTheIdAndNameOfTheStoredRow(): void
    {
        $this->processor(maxDeliveries: 2)->process(
            new Delivery(
                '{"order_id":"o-1"}',
                MetadataHeader::envelope(str_repeat('m', 300), "order.\x00placed", 1_765_476_000_123),
                1,
                self::SOURCE,
                null,
                2,
            ),
            $this->control,
        );

        $storedId = str_repeat('m', 255);
        $letter = $this->deadLetters()[0];
        self::assertSame($storedId, $letter->messageId);
        self::assertSame("order.\u{FFFD}placed", $letter->messageName);
        self::assertSame($storedId, $this->logger->records[0]['context']['message_id']);
        self::assertSame($storedId, $this->errorHandler->calls[0]['context']['message_id']);
        self::assertSame([[
            'event' => BrokerEvents::DEAD_LETTERED,
            'context' => [
                'source' => self::SOURCE,
                'message_id' => $storedId,
                'message_name' => "order.\u{FFFD}placed",
            ],
        ]], $this->events->records);
    }

    public function testTheLimitReportsToTheLoggerAndTheErrorHandlerWithTheSameContext(): void
    {
        $this->processor(maxDeliveries: 3)->process(
            $this->delivery('m-7', attempt: 2, deliveryCount: 4),
            $this->control,
        );

        $expected = [
            'queue' => self::SOURCE,
            'message_id' => 'm-7',
            'delivery_count' => 4,
            'max_deliveries' => 3,
            'attempt' => 2,
        ];
        self::assertCount(1, $this->logger->records);
        $record = $this->logger->records[0];
        self::assertSame(LogLevel::WARNING, $record['level']);
        self::assertSame('Consumer delivery limit reached; dead-lettered', $record['message']);
        $exception = $record['context']['exception'];
        self::assertInstanceOf(DeliveryLimitExceeded::class, $exception);
        self::assertSame('Delivery limit reached: 4 deliveries ended with no ack (limit 3)', $exception->getMessage());
        unset($record['context']['exception']);
        self::assertSame($expected, $record['context']);

        self::assertCount(1, $this->errorHandler->calls);
        self::assertSame($exception, $this->errorHandler->calls[0]['error']);
        self::assertSame($expected, $this->errorHandler->calls[0]['context']);
    }

    public function testTheLimitWorksWithoutEventsAndErrorHandler(): void
    {
        $this->processor(withObservers: false, maxDeliveries: 1)->process(
            $this->delivery(deliveryCount: 1),
            $this->control,
        );

        self::assertSame(['ack'], $this->control->calls);
        self::assertCount(1, $this->deadLetters());
        self::assertSame([], $this->events->records);
        self::assertSame([], $this->errorHandler->calls);
    }

    public function testAFailedDeadLetterWriteAtTheLimitPropagatesWithNoAck(): void
    {
        $error = new PDOException('db down');
        $store = new class($error) implements DeadLetterStore {
            public function __construct(
                private readonly PDOException $error,
            ) {}

            public function store(DeadLetter $deadLetter): void
            {
                throw $this->error;
            }

            public function find(string $id): ?DeadLetter
            {
                return null;
            }

            public function list(
                ?string $messageName = null,
                ?string $source = null,
                ?int $sinceMs = null,
                int $limit = 100,
                int $offset = 0,
                ?bool $replayed = null,
            ): array {
                return [];
            }

            public function count(
                ?string $messageName = null,
                ?string $source = null,
                ?int $sinceMs = null,
                ?bool $replayed = null,
            ): int {
                return 0;
            }

            public function markReplayed(string $id): void {}

            public function purge(?string $messageName = null, ?string $source = null, ?int $olderThanMs = null): int
            {
                return 0;
            }
        };

        try {
            $this->processor(maxDeliveries: 1, deadLetters: $store)->process(
                $this->delivery(deliveryCount: 1),
                $this->control,
            );
            self::fail('a failed dead letter write must propagate');
        } catch (PDOException $e) {
            self::assertSame($error, $e);
        }

        self::assertSame([], $this->control->calls, 'no ack');
        self::assertSame([], $this->dispatched);
        self::assertSame([], $this->transaction->calls);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidLimits(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('invalidLimits')]
    public function testALimitBelowOneThrowsAtConstruction(int $limit): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("maxDeliveries must be at least 1 or null, got {$limit}");

        $this->processor(maxDeliveries: $limit);
    }

    public function testALimitOfOneDeadLettersTheFirstReturnedDelivery(): void
    {
        $processor = $this->processor(maxDeliveries: 1);

        $processor->process($this->delivery('m-0', deliveryCount: 0), $this->control);
        $processor->process($this->delivery('m-1', deliveryCount: 1), $this->control);

        self::assertCount(1, $this->dispatched);
        self::assertSame('m-0', $this->dispatched[0]->messageId);
        self::assertCount(1, $this->deadLetters());
        self::assertSame('m-1', $this->deadLetters()[0]->messageId);
    }
}
