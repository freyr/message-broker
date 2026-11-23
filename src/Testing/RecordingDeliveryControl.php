<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\Consumer\DeliveryControl;
use RuntimeException;

/** Records the outcome calls of one delivery in order, and can refuse a retry. */
final class RecordingDeliveryControl implements DeliveryControl
{
    /** @var list<string> */
    public array $calls = [];

    public bool $retryFails = false;

    public function ack(): void
    {
        $this->calls[] = 'ack';
    }

    public function retry(int $delayMs, int $nextAttempt): void
    {
        if ($this->retryFails) {
            throw new RuntimeException('retry refused');
        }
        $this->calls[] = "retry:{$delayMs}:{$nextAttempt}";
    }
}
