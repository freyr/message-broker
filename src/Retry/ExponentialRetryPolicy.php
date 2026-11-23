<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Retry;

use Freyr\MessageBroker\Consumer\IncomingMessage;
use InvalidArgumentException;
use Throwable;

/**
 * Default retry policy: retries with a backoff delay until the attempt budget
 * is used, then dead-letters. It ignores the message.
 *
 * Two optional lists of throwable classes (or interfaces) select the decision
 * by the error. The checks run in this order: an error in `$deadLetterOn`
 * dead-letters (so an error in both lists never loses data); an error in
 * `$discardOn` is discarded, also at the last attempt; then the attempt limit
 * dead-letters; else the policy retries with the backoff delay. The match is
 * `instanceof`.
 */
final readonly class ExponentialRetryPolicy implements RetryPolicy
{
    private Backoff $backoff;

    /**
     * @param list<class-string<Throwable>> $deadLetterOn errors that dead-letter at once, with no retry
     * @param list<class-string<Throwable>> $discardOn errors that are acked and dropped
     * @throws InvalidArgumentException when an entry is not a class or interface that is a Throwable
     */
    public function __construct(
        private int $maxAttempts = 5,
        ?Backoff $backoff = null,
        private array $deadLetterOn = [],
        private array $discardOn = [],
    ) {
        foreach ([
            'deadLetterOn' => $deadLetterOn,
            'discardOn' => $discardOn,
        ] as $list => $classes) {
            foreach ($classes as $class) {
                // The docblock types are not enforced at runtime: check the real value.
                // @phpstan-ignore function.alreadyNarrowedType
                if (!is_a($class, Throwable::class, true)) {
                    throw new InvalidArgumentException(sprintf(
                        '%s: "%s" is not a class or interface that is a Throwable',
                        $list,
                        $class,
                    ));
                }
            }
        }
        $this->backoff = $backoff ?? Backoff::exponential(initialDelayMs: 1_000, maxDelayMs: 300_000);
    }

    public function decide(int $attempt, Throwable $error, IncomingMessage $message): RetryDecision
    {
        if (self::matches($error, $this->deadLetterOn)) {
            return RetryDecision::deadLetter();
        }

        if (self::matches($error, $this->discardOn)) {
            return RetryDecision::discard();
        }

        if ($attempt >= $this->maxAttempts) {
            return RetryDecision::deadLetter();
        }

        return RetryDecision::retryIn($this->backoff->delayForAttempt($attempt));
    }

    /** @param list<class-string<Throwable>> $classes */
    private static function matches(Throwable $error, array $classes): bool
    {
        foreach ($classes as $class) {
            if ($error instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
