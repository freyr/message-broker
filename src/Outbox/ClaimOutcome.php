<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

/**
 * Result of a drain publish callback: which claimed rows to mark published
 * and which to retry, each at its own available_at. A publisher
 * that confirms a whole batch at once (the AMQP publisher does) returns
 * all-or-nothing outcomes — one confirm wait covers the batch, so a failure
 * cannot attribute delivery per record — but the shape
 * permits partial outcomes for publishers that can attest per-record delivery.
 */
final readonly class ClaimOutcome
{
    /**
     * @param list<string> $publishedIds rows to mark published — published successfully
     * @param array<string, int> $retryAtMs row id => next available_at, epoch ms
     */
    public function __construct(
        public array $publishedIds,
        public array $retryAtMs,
    ) {}

    /** @param list<string> $ids */
    public static function published(array $ids): self
    {
        return new self($ids, []);
    }

    /** @param array<string, int> $retryAtMs */
    public static function retryAll(array $retryAtMs): self
    {
        return new self([], $retryAtMs);
    }
}
