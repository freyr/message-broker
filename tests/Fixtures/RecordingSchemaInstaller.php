<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures;

use Freyr\MessageBroker\Storage\SchemaInstaller;

/** A SchemaInstaller that records its calls and touches no database. */
final class RecordingSchemaInstaller implements SchemaInstaller
{
    /** @var list<int> */
    public array $sqlCalls = [];

    /** @var list<int> */
    public array $installCalls = [];

    public function sql(int $daysAhead): array
    {
        $this->sqlCalls[] = $daysAhead;

        return ['CREATE TABLE one (id INT)', "CREATE TABLE two (days INT DEFAULT {$daysAhead})"];
    }

    public function install(int $daysAhead): void
    {
        $this->installCalls[] = $daysAhead;
    }
}
