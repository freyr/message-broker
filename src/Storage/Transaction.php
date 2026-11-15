<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage;

/**
 * The database transaction of one delivery.
 *
 * It must wrap the SAME connection as the deduplication store and the
 * message handler: the dedup row and the work of the handler commit or roll
 * back together. A failure of begin() or rollBack() is an infrastructure
 * failure (the connection is gone), not a handler failure.
 */
interface Transaction
{
    public function begin(): void;

    public function commit(): void;

    public function rollBack(): void;
}
