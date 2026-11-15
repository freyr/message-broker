<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures;

use Freyr\MessageBroker\Observability\BrokerEvents;

final class RecordingEvents implements BrokerEvents
{
    /** @var list<array{event: string, context: array<string, mixed>}> */
    public array $records = [];

    public function record(string $event, array $context = []): void
    {
        $this->records[] = [
            'event' => $event,
            'context' => $context,
        ];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn (array $r): string => $r['event'], $this->records);
    }
}
