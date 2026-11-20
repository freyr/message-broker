<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Observability;

/**
 * Optional lifecycle listener for lifecycle transitions: produce, relay,
 * dispatch, dedup-hit, retry, discard, dead-letter, replay, replay skip, abandon,
 * the webhook deliveries (delivered, retried, dead-lettered, abandoned), and the
 * webhook flow control (a lane leaves circulation, is blocked or returns, a wait for permits, a lane at
 * its limit). Mirrors ErrorHandler's minimal,
 * optional shape — the library's behavior never depends on it. For
 * metrics/tracing: derive throughput, lag, duplicate-rate, retry rate, and DLQ flow.
 */
interface BrokerEvents
{
    public const string PRODUCED = 'message.produced';
    public const string RELAYED = 'batch.relayed';
    public const string DISPATCHED = 'message.dispatched';
    public const string DEDUPLICATED = 'message.deduplicated';
    public const string DEAD_LETTERED = 'message.dead_lettered';
    public const string RETRIED = 'message.retried';
    public const string DISCARDED = 'message.discarded';
    /** A dead letter replayed into the outbox; context: message_id, message_name, lane, target (the source of the dead letter). */
    public const string REPLAYED = 'message.replayed';
    /**
     * A consumer acked a replay for a different queue with no dispatch; context: consumer, source,
     * target (the x-replay-target value), message_id (string or null), message_name (string or null).
     */
    public const string REPLAY_SKIPPED = 'message.replay_skipped';
    /** An operator abandoned an outbox row; context: message_id, count, reason (string or null). */
    public const string ABANDONED = 'message.abandoned';
    /** A webhook delivery was delivered; context: lane, message_id, delivery_id, attempts, request_ms. */
    public const string WEBHOOK_DELIVERED = 'webhook.delivered';
    /** A failed webhook delivery waits for a retry; context: lane, message_id, delivery_id, attempts, retry_at (epoch ms). */
    public const string WEBHOOK_RETRIED = 'webhook.retried';
    /**
     * A webhook delivery went to the dead letters after a result; context: lane, message_id, delivery_id,
     * attempts, reason ('failed' or 'refused'), reason_code (the reason code of a refused result, else null).
     */
    public const string WEBHOOK_DEAD_LETTERED = 'webhook.dead_lettered';
    /** An operator abandoned the old webhook deliveries of one lane (webhook:abandon); context: lane, count. */
    public const string WEBHOOK_ABANDONED = 'webhook.abandoned';
    /**
     * The circuit breaker took a webhook lane out of circulation for its open time; context: lane. The
     * worker whose record() call made the trip records it, so each trip has one such event (a trip that
     * blocks the lane records WEBHOOK_LANE_BLOCKED in its place).
     */
    public const string WEBHOOK_LANE_OPENED = 'webhook.lane_opened';
    /**
     * A webhook lane is back in circulation after its open time; context: lane. The worker whose
     * record() call started the first window after the open time records it, so each trip has one such event.
     */
    public const string WEBHOOK_LANE_RETURNED = 'webhook.lane_returned';
    /**
     * The circuit breaker blocked a webhook lane: it stays out of circulation until an operator closes it
     * (webhook:lanes --close); context: lane. The worker whose record() call made the trip records it.
     */
    public const string WEBHOOK_LANE_BLOCKED = 'webhook.lane_blocked';
    /**
     * The rate limiter granted 0 permits to each lane of a pass and the worker sleeps; context: lanes (the
     * lanes with due rows that got a grant of 0, in the order of the pass), sleep_ms. The event names no
     * bucket: the port returns only the grants.
     */
    public const string WEBHOOK_PERMIT_WAIT = 'webhook.permit_wait';
    /**
     * The rate limiter granted a webhook lane fewer permits than its due rows (its due count, capped at the
     * row limit for each lane); context: lane, wanted, granted (also 0).
     */
    public const string WEBHOOK_LANE_AT_LIMIT = 'webhook.lane_at_limit';

    /** @param array<string, mixed> $context */
    public function record(string $event, array $context = []): void;
}
