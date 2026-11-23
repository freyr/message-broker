<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DeadLetter;

use InvalidArgumentException;

/**
 * The filter of the reads and the delete of the {@see DeadLetterStore} port.
 * Each criterion is optional (null means "no condition"); all set criteria
 * combine with AND. The conditions use only the columns of a dead letter:
 *
 *   messageName    message_name equal
 *   source         source equal
 *   errorClass     error_class equal (the full class name; no subclass, no prefix)
 *   messageId      message_id equal
 *   failedFromMs   failed_at >= failedFromMs (inclusive, epoch ms)
 *   failedUntilMs  failed_at <  failedUntilMs (exclusive, epoch ms)
 *   minAttempts    attempts >= minAttempts
 *   replayed       true: replayed_at IS NOT NULL; false: replayed_at IS NULL
 *   replayable     replayable equal
 */
final readonly class DeadLetterFilter
{
    /**
     * @throws InvalidArgumentException when minAttempts is below 0, or when failedFromMs is not lower
     *                                  than failedUntilMs
     */
    public function __construct(
        public ?string $messageName = null,
        public ?string $source = null,
        public ?string $errorClass = null,
        public ?string $messageId = null,
        public ?int $failedFromMs = null,
        public ?int $failedUntilMs = null,
        public ?int $minAttempts = null,
        public ?bool $replayed = null,
        public ?bool $replayable = null,
    ) {
        if ($minAttempts !== null && $minAttempts < 0) {
            throw new InvalidArgumentException("minAttempts must be 0 or more, got {$minAttempts}");
        }
        if ($failedFromMs !== null && $failedUntilMs !== null && $failedFromMs >= $failedUntilMs) {
            throw new InvalidArgumentException(
                "failedFromMs ({$failedFromMs}) must be lower than failedUntilMs ({$failedUntilMs})",
            );
        }
    }

    /** True when no criterion is set: the filter matches every row. */
    public function isEmpty(): bool
    {
        return $this->messageName === null
            && $this->source === null
            && $this->errorClass === null
            && $this->messageId === null
            && $this->failedFromMs === null
            && $this->failedUntilMs === null
            && $this->minAttempts === null
            && $this->replayed === null
            && $this->replayable === null;
    }

    /** A copy with a different replay state; the other criteria stay. */
    public function withReplayed(?bool $replayed): self
    {
        return new self(
            messageName: $this->messageName,
            source: $this->source,
            errorClass: $this->errorClass,
            messageId: $this->messageId,
            failedFromMs: $this->failedFromMs,
            failedUntilMs: $this->failedUntilMs,
            minAttempts: $this->minAttempts,
            replayed: $replayed,
            replayable: $this->replayable,
        );
    }
}
