<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DeadLetter;

use RuntimeException;

/** The replay finds no dead letter with the id (unknown, or purged). */
final class DeadLetterNotFound extends RuntimeException
{
    public function __construct(string $deadLetterId)
    {
        parent::__construct("Dead letter '{$deadLetterId}' not found");
    }
}
