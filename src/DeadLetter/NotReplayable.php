<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DeadLetter;

use RuntimeException;

/**
 * The replay refuses a dead letter that it cannot replay: the row has the mark
 * replayable = false, or its body is not the canonical document (metadata +
 * payload). The message names the dead letter id and the reason.
 */
final class NotReplayable extends RuntimeException
{
    public function __construct(string $deadLetterId, string $reason)
    {
        parent::__construct("Dead letter '{$deadLetterId}' is not replayable: {$reason}");
    }
}
