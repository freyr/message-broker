<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DeadLetter;

use RuntimeException;

/**
 * The replay refuses a dead letter that is replayed (replayed_at is set), also
 * when another process set the mark during this replay. No option replays a
 * replayed row again: a message that failed again after its replay has a new
 * open row.
 */
final class AlreadyReplayed extends RuntimeException
{
    public function __construct(string $deadLetterId)
    {
        parent::__construct("Dead letter '{$deadLetterId}' is already replayed");
    }
}
