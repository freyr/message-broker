<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures;

use DateTimeImmutable;
use Freyr\MessageBroker\Outbox\OutboxRetention;
use Freyr\MessageBroker\Outbox\RetentionReport;

/** An OutboxRetention that records its calls and answers with a prepared report. */
final class RecordingOutboxRetention implements OutboxRetention
{
    /** @var list<int> */
    public array $ensureCalls = [];

    /** @var list<array{int, bool, bool}> retentionDays, dryRun, dropUnpublished */
    public array $purgeCalls = [];

    public function __construct(
        public RetentionReport $report = new RetentionReport(new DateTimeImmutable('2026-03-03 00:00:00 UTC'), [], [], []),
    ) {}

    public function ensureCapacity(int $daysAhead): array
    {
        $this->ensureCalls[] = $daysAhead;

        return ['unit-a', 'unit-b'];
    }

    public function purge(int $retentionDays, bool $dryRun = false, bool $dropUnpublished = false): RetentionReport
    {
        $this->purgeCalls[] = [$retentionDays, $dryRun, $dropUnpublished];

        return $this->report;
    }
}
