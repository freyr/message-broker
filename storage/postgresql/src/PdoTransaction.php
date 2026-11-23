<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql;

use Freyr\MessageBroker\Storage\Transaction;
use PDO;

/**
 * Transaction on a PDO connection. Exceptions of PDO (error mode exception)
 * propagate unchanged.
 */
final readonly class PdoTransaction implements Transaction
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function begin(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }
}
