<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Retry;

use Freyr\MessageBroker\Consumer\IncomingMessage;
use InvalidArgumentException;
use Throwable;

/**
 * Selects a retry policy for each message name. The match is exact (no
 * pattern, no wildcard); a name with no entry uses the default policy.
 */
final readonly class RetryPolicyMap implements RetryPolicy
{
    /**
     * @param array<string, RetryPolicy> $policies keyed by message name
     * @throws InvalidArgumentException when a key is empty or a value is not a RetryPolicy
     */
    public function __construct(
        private RetryPolicy $default,
        private array $policies = [],
    ) {
        foreach ($policies as $name => $policy) {
            if ($name === '') {
                throw new InvalidArgumentException('RetryPolicyMap: a message name must not be empty');
            }
            // The docblock type is not enforced at runtime: check the real value.
            // @phpstan-ignore instanceof.alwaysTrue
            if (!$policy instanceof RetryPolicy) {
                throw new InvalidArgumentException(sprintf(
                    'RetryPolicyMap: the value for "%s" is not a RetryPolicy',
                    $name,
                ));
            }
        }
    }

    public function decide(int $attempt, Throwable $error, IncomingMessage $message): RetryDecision
    {
        return ($this->policies[$message->messageName] ?? $this->default)->decide($attempt, $error, $message);
    }
}
