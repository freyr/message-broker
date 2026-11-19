<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Outbox\IdleSleep;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Worker\WorkerLimitGuard;
use Freyr\MessageBroker\Worker\WorkerLimits;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Random\Randomizer;
use Symfony\Component\Uid\Uuid;
use UnexpectedValueException;

/**
 * The webhook delivery loop. One worker serves all lanes; any number of
 * identical workers can run. Each pass:
 *
 *  1. Expired rows, at an interval: claim the due, unfinished rows that are
 *     older than the maximum age, from all lanes, and move them to the dead
 *     letters with the reason "expired".
 *  2. Lanes: the lanes of SubscriptionProvider::lanes(), read at a refresh
 *     interval, kept to the lane filter, in a random order for each claim.
 *  3. Claim: at most the batch size, at most the row limit of each lane, with
 *     a lease that the store commits before a request starts.
 *  4. Pairs: the subscription of each claimed delivery (find(lane)). A lane
 *     with no subscription sends no request and uses no attempt; its row
 *     waits for the maximum age.
 *  5. Send: one send() call with no open transaction.
 *  6. Results: delivered; else the retry policy decides between a retry and a
 *     dead letter (source `webhook:<lane>`). The results go to the store in
 *     one complete() call, with the lease check.
 *
 * A database error or a sender error propagates and stops the worker
 * (crash-only): the claimed rows are due again when their lease ends.
 *
 * Dead letters: the error class is the reason (`failed`, `refused` or
 * `expired`); the error message is the text of the last result (for a
 * refused result: `<reason code>: <text>`); the trace is empty; the attempt
 * count is the attempts of the row, with the request of the last result.
 *
 * The time comes from symfony/clock through {@see EpochMillis}.
 */
final class DeliveryLoop
{
    public const string SOURCE_PREFIX = 'webhook:';
    public const string REASON_FAILED = 'failed';
    public const string REASON_REFUSED = 'refused';
    public const string REASON_EXPIRED = 'expired';

    private const int DAY_MS = 86_400_000;

    private bool $shouldStop = false;

    /** @var array<int, string>|null the lanes of the last read; null before the first read */
    private ?array $lanes = null;

    private int $lanesReadAt = 0;

    private ?int $expiredCheckedAt = null;

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
     *                                  the longest send call, or the maximum age plus the
     *                                  longest retry delay is not shorter than the retention period
     */
    public function run(?WorkerLimits $limits = null): void
    {
        $this->assertStartConditions();
        $guard = new WorkerLimitGuard($limits ?? new WorkerLimits());
        $guard->start();
        $this->registerSignalHandlers();

        while (!$this->shouldStop) {
            $delivered = $this->deliverOnce();
            if ($delivered === 0) {
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
     * One pass: expired rows (at their interval), lanes, claim, pairs, send, results.
     *
     * @return int the rows that this pass delivered
     */
    public function deliverOnce(): int
    {
        $now = EpochMillis::now();
        $this->expire($now);

        $lanes = $this->lanes($now);
        if ($lanes === []) {
            return 0;
        }

        $claimed = $this->store->claim($lanes, $this->config->limitForEachLane, $this->config->batchSize, $this->config->leaseMs);
        $pairs = $this->pairs($claimed);
        if ($pairs === []) {
            return 0;
        }

        $results = $this->sender->send($pairs);

        return $this->complete($pairs, $results);
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

        $longestDelayMs = $this->retryPolicy->longestDelayMs();
        if ($this->config->maxAgeMs + $longestDelayMs >= $this->config->retentionDays * self::DAY_MS) {
            throw new InvalidArgumentException(sprintf(
                'The maximum age (%d ms) plus the longest retry delay (%d ms) must be shorter than the retention period (%d days)',
                $this->config->maxAgeMs,
                $longestDelayMs,
                $this->config->retentionDays,
            ));
        }
    }

    /** Move the due, unfinished rows older than the maximum age to the dead letters, at the check interval. */
    private function expire(int $now): void
    {
        if ($this->expiredCheckedAt !== null && $now - $this->expiredCheckedAt < $this->config->expiredCheckIntervalMs) {
            return;
        }
        $this->expiredCheckedAt = $now;

        $message = sprintf('The delivery is older than the maximum age of %d ms', $this->config->maxAgeMs);
        do {
            $expired = $this->store->claimExpired($now - $this->config->maxAgeMs, $this->config->batchSize, $this->config->leaseMs);
            if ($expired === []) {
                return;
            }
            $completions = array_map(
                fn (ClaimedDelivery $claimed): DeliveryCompletion => DeliveryCompletion::dead(
                    $claimed,
                    $this->deadLetter($claimed->delivery, self::REASON_EXPIRED, $message, $claimed->delivery->attempts),
                ),
                $expired,
            );
            $changed = array_flip($this->store->complete($completions));
            foreach ($expired as $claimed) {
                $record = $claimed->delivery;
                if (isset($changed[$record->id])) {
                    $this->events?->record(BrokerEvents::WEBHOOK_EXPIRED, self::context($record) + [
                        'attempts' => $record->attempts,
                    ]);
                }
            }
        } while (count($expired) === $this->config->batchSize);
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
            DeliveryCompletion::dead($claimed, $this->deadLetter($record, $reason, $message, $attempts)),
            [BrokerEvents::WEBHOOK_DEAD_LETTERED, self::context($record) + [
                'attempts' => $attempts,
                'reason' => $reason,
                'reason_code' => $result->reasonCode,
            ]],
        ];
    }

    private function deadLetter(DeliveryRecord $record, string $reason, string $message, int $attempts): DeadLetter
    {
        return new DeadLetter(
            id: Uuid::v7()->toString(),
            source: self::SOURCE_PREFIX.$record->lane,
            messageId: $record->messageId,
            messageName: $record->messageName,
            body: $record->body,
            headers: $record->headers,
            errorClass: $reason,
            errorMessage: $message,
            errorTrace: '',
            attempts: $attempts,
            failedAt: EpochMillis::now(),
        );
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
