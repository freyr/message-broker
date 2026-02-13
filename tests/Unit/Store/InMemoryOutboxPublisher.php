<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Store;

use Freyr\MessageBroker\Contracts\OutboxPublisherInterface;
use Symfony\Component\Messenger\Envelope;

/**
 * In-memory outbox publisher for unit testing.
 *
 * Stores published envelopes in an array for assertion.
 */
final class InMemoryOutboxPublisher implements OutboxPublisherInterface
{
    /** @var list<Envelope> */
    private array $published = [];

    public function publish(Envelope $envelope): void
    {
        $this->published[] = $envelope;
    }

    /**
     * @return list<Envelope>
     */
    public function getPublishedEnvelopes(): array
    {
        return $this->published;
    }

    public function getLastPublishedEnvelope(): ?Envelope
    {
        return $this->published[array_key_last($this->published)] ?? null;
    }

    public function count(): int
    {
        return count($this->published);
    }
}
