<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Observability;

/**
 * Optional lifecycle listener for lifecycle transitions: produce, relay,
 * dispatch, dedup-hit, retry, discard, dead-letter, replay, replay skip, abandon. Mirrors ErrorHandler's minimal,
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

    /** @param array<string, mixed> $context */
    public function record(string $event, array $context = []): void;
}
