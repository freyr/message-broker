<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

/** What a {@see DeliveryRetryPolicy} decides after a result that is not delivered: a retry at a time, or a dead letter. */
final readonly class DeliveryDecision
{
    /** @param int|null $retryAt epoch milliseconds; null = dead letter */
    private function __construct(
        public ?int $retryAt,
    ) {}

    /** @param int $atMs the time of the next claim, epoch milliseconds */
    public static function retryAt(int $atMs): self
    {
        return new self($atMs);
    }

    public static function deadLetter(): self
    {
        return new self(null);
    }

    public function isDeadLetter(): bool
    {
        return $this->retryAt === null;
    }
}
