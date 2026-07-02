<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

/**
 * Result of a competing-drain publish callback: which claimed rows to delete
 * (published) and which to retry, each at its own available_at. The AMQP
 * publisher returns all-or-nothing outcomes — one confirm wait covers the
 * batch, so a failure cannot attribute delivery per record — but the shape
 * permits partial outcomes for publishers that can attest per-record delivery.
 */
final readonly class ClaimOutcome
{
    /**
     * @param list<string> $publishedIds rows to delete — published successfully
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
