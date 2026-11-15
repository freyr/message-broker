<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Retry;

use Freyr\MessageBroker\Consumer\IncomingMessage;
use Throwable;

/**
 * Decides what happens to a delivery whose handler failed: retry after a
 * delay, dead-letter, or discard. The message is passed so that a policy can
 * decide per message name.
 */
interface RetryPolicy
{
    /** @param int $attempt the attempt that failed, starting at 1 */
    public function decide(int $attempt, Throwable $error, IncomingMessage $message): RetryDecision;
}
