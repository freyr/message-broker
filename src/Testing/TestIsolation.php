<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use PHPUnit\Framework\TestCase;

/**
 * The transport-neutral part of the isolation of parallel test workers.
 *
 * Infection sets the environment variable TEST_TOKEN for each worker process.
 * A test uses isolated() for each name of a shared resource (a queue, an
 * exchange, a topic), so that two workers never use the same name.
 *
 * @phpstan-require-extends TestCase
 */
trait TestIsolation
{
    /**
     * The worker index of a parallel run. Null in a plain PHPUnit run.
     */
    protected static function workerToken(): ?string
    {
        $token = getenv('TEST_TOKEN');

        return is_string($token) && preg_match('/^\d+$/', $token) === 1 ? $token : null;
    }

    /**
     * The name of a queue, an exchange or a topic of this worker: the shared
     * name in a plain PHPUnit run, <name>_t<token> in a parallel run.
     */
    protected static function isolated(string $name): string
    {
        $token = self::workerToken();

        return $token === null ? $name : "{$name}_t{$token}";
    }
}
