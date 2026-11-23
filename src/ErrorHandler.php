<?php

declare(strict_types=1);

namespace Freyr\MessageBroker;

use Throwable;

/**
 * Optional observability hook for relays and consumers: invoked with full
 * context whenever a failure is handled (backoff scheduled, message
 * dead-lettered, …). For logging/metrics/alerting — the library's failure
 * ROUTING never depends on it.
 */
interface ErrorHandler
{
    /** @param array<string, mixed> $context */
    public function handle(Throwable $error, array $context = []): void;
}
