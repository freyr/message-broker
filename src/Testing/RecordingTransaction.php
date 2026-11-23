<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\Storage\Transaction;
use Throwable;

/** Records begin, commit and rollBack in order; begin and rollBack can be made to fail. */
final class RecordingTransaction implements Transaction
{
    /** @var list<string> */
    public array $calls = [];

    public ?Throwable $beginFails = null;

    public ?Throwable $rollBackFails = null;

    public function begin(): void
    {
        $this->calls[] = 'begin';
        if ($this->beginFails !== null) {
            throw $this->beginFails;
        }
    }

    public function commit(): void
    {
        $this->calls[] = 'commit';
    }

    public function rollBack(): void
    {
        $this->calls[] = 'rollback';
        if ($this->rollBackFails !== null) {
            throw $this->rollBackFails;
        }
    }
}
