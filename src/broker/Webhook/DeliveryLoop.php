<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Outbox\IdleSleep;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Worker\WorkerLimitGuard;
use Freyr\MessageBroker\Worker\WorkerLimits;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Random\Randomizer;
use Symfony\Component\Clock\Clock;
use UnexpectedValueException;

/**
 * The webhook delivery loop. One worker serves all lanes; any number of
 * identical workers can run. Each pass:
 *
 *  1. Lanes: the lanes of SubscriptionProvider::lanes(), read at a refresh
 *     interval, kept to the lane filter, in a random order for each claim.
 *  2. Open lanes: the lanes of CircuitBreaker::openLanes() leave the list.
 *     Their rows wait with their attempts and retry times.
 *  3. Permits: RateLimiter::acquire(batch size). With a grant of 0, the
 *     worker sleeps the permit wait (through symfony/clock) and does not claim.
 *  4. Claim: at most the grant, at most the row limit of each lane, with a
 *     lease that the store commits before a request starts. The permits of
 *     the rows that the claim did not get go back (giveBack()).
 *  5. Pairs: the subscription of each claimed delivery (find(lane)). A lane
 *     with no subscription sends no request and uses no attempt; its row
 *     waits until the subscription returns or an operator abandons it.
 *  6. Send: one send() call with no open transaction.
 *  7. Results: delivered; else the retry policy decides between a retry and a
 *     dead letter (source `webhook:<lane>`). The results go to the store in
 *     one complete() call, with the lease check.
 *  8. Record: the requests and the failures of each lane of the batch go to
 *     CircuitBreaker::record() in one call. A failure is each result that is
 *     not delivered, and a delivered result above the slow limit of the
 *     {@see CircuitBreakerPolicy} (the row stays delivered).
 *
 * A delivery has no time limit: a row waits until it is delivered, reaches
 * the retry limit, or gets a permanent refusal. An operator ends old rows
 * with DeliveryStore::abandon() (the command message-broker:webhook:abandon).
 *
 * A database error (also of the rate limiter or the circuit breaker) or a
 * sender error propagates and stops the worker (crash-only): the claimed rows
 * are due again when their lease ends. With no rate limiter and no circuit
 * breaker, the loop uses {@see NoRateLimit} and {@see NoCircuitBreaker}.
 *
 * Dead letters: the error class is the reason (`failed` or `refused`); the error message is the text of the last result (for a
 * refused result: `<reason code>: <text>`); the trace is empty; the attempt
 * count is the attempts of the row, with the request of the last result.
 *
 * The time comes from symfony/clock through {@see EpochMillis}.
 */
final class DeliveryLoop
{
    public const string SOURCE_PREFIX = DeliveryDeadLetter::SOURCE_PREFIX;
    public const string REASON_FAILED = DeliveryDeadLetter::REASON_FAILED;
    public const string REASON_REFUSED = DeliveryDeadLetter::REASON_REFUSED;

    private bool $shouldStop = false;

    /** @var array<int, string>|null the lanes of the last read; null before the first read */
    private ?array $lanes = null;

    private int $lanesReadAt = 0;

    /** The last pass slept the permit wait, so the run skips the idle sleep. */
    private bool $waitedForPermits = false;

    private readonly Randomizer $randomizer;

    public function __construct(
        private readonly DeliveryStore $store,
        private readonly DeliverySender $sender,
        private readonly SubscriptionProvider $subscriptions,
        private readonly DeliveryRetryPolicy $retryPolicy = new DefaultDeliveryRetryPolicy(),
        private readonly DeliveryLoopConfig $config = new DeliveryLoopConfig(),
        ?Randomizer $randomizer = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?BrokerEvents $events = null,
        private readonly RateLimiter $rateLimiter = new NoRateLimit(),
        private readonly CircuitBreaker $circuitBreaker = new NoCircuitBreaker(),
        private readonly CircuitBreakerPolicy $breakerPolicy = new CircuitBreakerPolicy(),
    ) {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    /**
     * Long-running entrypoint; stops on SIGTERM/SIGINT when pcntl is
     * available, and when a limit of {@see WorkerLimits} is reached. A limit
     * is checked at the end of each pass, so the run can deliver up to one
     * batch above a message limit; the message count is the delivered rows.
     *
     * @throws InvalidArgumentException when a start check fails: the lease is not longer than
     *                                  the longest send call, or the batch size is larger than
     *                                  the burst capacity of the configuration
     */
    public function run(?WorkerLimits $limits = null): void
    {
        $this->assertStartConditions();
        $guard = new WorkerLimitGuard($limits ?? new WorkerLimits());
        $guard->start();
        $this->registerSignalHandlers();

        while (!$this->shouldStop) {
            $delivered = $this->deliverOnce();
            if ($delivered === 0 && !$this->waitedForPermits) {
                usleep(IdleSleep::micros($this->config->idleSleepMs, intdiv($this->config->idleSleepMs, 4)));
            }

            $guard->recordMessages($delivered);
            $reason = $guard->stopReason();
            if ($reason !== null) {
                $this->logger->info('Worker limit reached; the worker stops', [
                    'reason' => $reason->value,
                    'messages' => $guard->messages(),
                    'failures' => $guard->failures(),
                    'elapsedSeconds' => $guard->elapsedMs() / 1_000.0,
                    'memoryBytes' => $guard->usedMemory(),
                ]);
                $this->stop();
            }
        }
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    /**
     * One pass: lanes, open lanes, permits, claim, give back, pairs, send,
     * results, record.
     *
     * @return int the rows that this pass delivered
     */
    public function deliverOnce(): int
    {
        $this->waitedForPermits = false;
        $now = EpochMillis::now();

        $lanes = $this->lanes($now);
        if ($lanes === []) {
            return 0;
        }
        $lanes = array_values(array_diff($lanes, $this->circuitBreaker->openLanes()));
        if ($lanes === []) {
            return 0;
        }

        $granted = $this->rateLimiter->acquire($this->config->batchSize);
        if ($granted === 0) {
            $this->waitForPermits();

            return 0;
        }

        $claimed = $this->store->claim($lanes, $this->config->limitForEachLane, $granted, $this->config->leaseMs);
        $unused = $granted - count($claimed);
        if ($unused > 0) {
            $this->rateLimiter->giveBack($unused);
        }

        $pairs = $this->pairs($claimed);
        if ($pairs === []) {
            return 0;
        }

        $results = $this->sender->send($pairs);
        $delivered = $this->complete($pairs, $results);
        $this->record($pairs, $results);

        return $delivered;
    }

    private function assertStartConditions(): void
    {
        $maxSendMs = $this->sender->maxSendMs();
        if ($this->config->leaseMs <= $maxSendMs) {
            throw new InvalidArgumentException(sprintf(
                'The lease (%d ms) must be longer than the longest send call of the sender (%d ms)',
                $this->config->leaseMs,
                $maxSendMs,
            ));
        }

        $burstCapacity = $this->config->burstCapacity;
        if ($burstCapacity !== null && $this->config->batchSize > $burstCapacity) {
            throw new InvalidArgumentException(sprintf(
                'The batch size (%d) must not be larger than the burst capacity of the rate limit (%d)',
                $this->config->batchSize,
                $burstCapacity,
            ));
        }
    }

    /** No permit: record the wait and sleep the permit wait through the clock, so a test clock does not wait. */
    private function waitForPermits(): void
    {
        $this->events?->record(BrokerEvents::WEBHOOK_PERMIT_WAIT, [
            'wanted' => $this->config->batchSize,
            'sleep_ms' => $this->config->permitWaitMs,
        ]);
        Clock::get()->sleep($this->config->permitWaitMs / 1_000);
        $this->waitedForPermits = true;
    }

    /**
     * Give the requests and the failures of each lane of the batch to the circuit breaker in one call,
     * and record an event for each lane that left circulation or returned.
     *
     * @param non-empty-list<DeliveryPair> $pairs
     * @param array<string, DeliveryResult> $results the result of each pair (complete() checked them)
     */
    private function record(array $pairs, array $results): void
    {
        /** @var array<string, array{lane: string, requests: int, failures: int}> $byLane the key is not a lane ID that PHP can make an int */
        $byLane = [];
        foreach ($pairs as $pair) {
            $record = $pair->claimed->delivery;
            $key = "lane:{$record->lane}";
            $byLane[$key] ??= [
                'lane' => $record->lane,
                'requests' => 0,
                'failures' => 0,
            ];
            ++$byLane[$key]['requests'];
            if ($this->breakerPolicy->countsAsFailure($results[$record->id])) {
                ++$byLane[$key]['failures'];
            }
        }

        $transitions = $this->circuitBreaker->record(array_values(array_map(
            static fn (array $c): LaneCounts => new LaneCounts($c['lane'], $c['requests'], $c['failures']),
            $byLane,
        )));
        foreach ($transitions->left as $lane) {
            $this->events?->record(BrokerEvents::WEBHOOK_LANE_OPENED, [
                'lane' => $lane,
            ]);
        }
        foreach ($transitions->returned as $lane) {
            $this->events?->record(BrokerEvents::WEBHOOK_LANE_RETURNED, [
                'lane' => $lane,
            ]);
        }
    }

    /** @return list<string> the lanes of the next claim, in a random order */
    private function lanes(int $now): array
    {
        if ($this->lanes === null || $now - $this->lanesReadAt >= $this->config->laneRefreshMs) {
            $lanes = $this->subscriptions->lanes();
            if ($this->config->laneFilter !== null) {
                $lanes = array_intersect($lanes, $this->config->laneFilter);
            }
            $this->lanes = $lanes;
            $this->lanesReadAt = $now;
        }

        /** @var list<string> */
        return $this->randomizer->shuffleArray($this->lanes);
    }

    /**
     * @param list<ClaimedDelivery> $claimed
     *
     * @return list<DeliveryPair> the claimed deliveries whose lane has a subscription
     */
    private function pairs(array $claimed): array
    {
        $subscriptions = [];
        $pairs = [];
        foreach ($claimed as $delivery) {
            $lane = $delivery->delivery->lane;
            if (!array_key_exists($lane, $subscriptions)) {
                $subscriptions[$lane] = $this->subscriptions->find($lane);
            }
            if ($subscriptions[$lane] !== null) {
                $pairs[] = new DeliveryPair($delivery, $subscriptions[$lane]);
            }
        }

        return $pairs;
    }

    /**
     * Turn the results into outcomes, write them, and record an event for each row that the store changed.
     *
     * @param non-empty-list<DeliveryPair> $pairs
     * @param array<string, DeliveryResult> $results
     *
     * @return int the rows that the store marked delivered
     */
    private function complete(array $pairs, array $results): int
    {
        $completions = [];
        $events = [];
        foreach ($pairs as $pair) {
            $record = $pair->claimed->delivery;
            $result = $results[$record->id]
                ?? throw new UnexpectedValueException("The sender returned no result for the delivery {$record->id}");
            [$completions[], $events[$record->id]] = $this->outcome($pair->claimed, $result);
        }

        $delivered = 0;
        foreach ($this->store->complete($completions) as $id) {
            [$event, $context] = $events[$id];
            if ($event === BrokerEvents::WEBHOOK_DELIVERED) {
                ++$delivered;
            }
            $this->events?->record($event, $context);
        }

        return $delivered;
    }

    /** @return array{DeliveryCompletion, array{string, array<string, mixed>}} the outcome and its event */
    private function outcome(ClaimedDelivery $claimed, DeliveryResult $result): array
    {
        $record = $claimed->delivery;
        $attempts = $record->attempts + 1;
        if ($result->kind === DeliveryResultKind::Delivered) {
            return [DeliveryCompletion::delivered($claimed), [BrokerEvents::WEBHOOK_DELIVERED, self::context($record) + [
                'attempts' => $attempts,
                'request_ms' => $result->requestMs,
            ]]];
        }

        $decision = $this->retryPolicy->decide($attempts, $result, $record);
        if ($decision->retryAt !== null) {
            return [DeliveryCompletion::retry($claimed, $decision->retryAt), [BrokerEvents::WEBHOOK_RETRIED, self::context($record) + [
                'attempts' => $attempts,
                'retry_at' => $decision->retryAt,
            ]]];
        }

        $refused = $result->kind === DeliveryResultKind::Refused;
        $reason = $refused ? self::REASON_REFUSED : self::REASON_FAILED;
        $message = $refused ? "{$result->reasonCode}: {$result->text}" : $result->text;

        return [
            DeliveryCompletion::dead($claimed, DeliveryDeadLetter::of($record, $reason, $message, $attempts, EpochMillis::now())),
            [BrokerEvents::WEBHOOK_DEAD_LETTERED, self::context($record) + [
                'attempts' => $attempts,
                'reason' => $reason,
                'reason_code' => $result->reasonCode,
            ]],
        ];
    }

    /** @return array{lane: string, message_id: string, delivery_id: string} */
    private static function context(DeliveryRecord $record): array
    {
        return [
            'lane' => $record->lane,
            'message_id' => $record->messageId,
            'delivery_id' => $record->id,
        ];
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
