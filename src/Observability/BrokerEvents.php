<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Observability;

/**
 * Optional lifecycle listener for success-path transitions: produce, relay,
 * dispatch, dedup-hit, dead-letter, replay. Mirrors ErrorHandler's minimal,
 * optional shape — the library's behavior never depends on it. For
 * metrics/tracing: derive throughput, lag, duplicate-rate, and DLQ flow.
 */
interface BrokerEvents
{
    public const string PRODUCED = 'message.produced';
    public const string RELAYED = 'batch.relayed';
    public const string DISPATCHED = 'message.dispatched';
    public const string DEDUPLICATED = 'message.deduplicated';
    public const string DEAD_LETTERED = 'message.dead_lettered';
    public const string REPLAYED = 'message.replayed';

    /** @param array<string, mixed> $context */
    public function record(string $event, array $context = []): void;
}
