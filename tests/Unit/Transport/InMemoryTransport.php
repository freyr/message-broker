<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Transport;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * In-memory transport for unit testing.
 *
 * Stores envelopes in memory without any external dependencies.
 * Provides inspection methods for test assertions.
 */
final class InMemoryTransport implements TransportInterface
{
    /** @var array<Envelope> */
    private array $sent = [];

    /** @var array<Envelope> */
    private array $acknowledged = [];

    /** @var array<Envelope> */
    private array $rejected = [];

    public function __construct(
        private readonly SerializerInterface $serializer,
    ) {
    }

    public function send(Envelope $envelope): Envelope
    {
        $this->sent[] = $envelope;

        return $envelope;
    }

    public function get(): iterable
    {
        if (empty($this->sent)) {
            return [];
        }

        // Return all unacknowledged messages
        $unacknowledged = array_filter(
            $this->sent,
            fn(Envelope $e) => !in_array($e, $this->acknowledged, true) && !in_array($e, $this->rejected, true)
        );

        return array_values($unacknowledged);
    }

    public function ack(Envelope $envelope): void
    {
        $this->acknowledged[] = $envelope;
    }

    public function reject(Envelope $envelope): void
    {
        $this->rejected[] = $envelope;
    }

    /**
     * Get all sent envelopes.
     *
     * @return array<Envelope>
     */
    public function getSentEnvelopes(): array
    {
        return $this->sent;
    }

    /**
     * Get the last sent envelope.
     */
    public function getLastEnvelope(): ?Envelope
    {
        if (empty($this->sent)) {
            return null;
        }

        return $this->sent[array_key_last($this->sent)];
    }

    /**
     * Get serialized representation of the last sent envelope.
     *
     * This is useful for testing serialization format.
     *
     * @return array{body: string, headers: array<string, mixed>}|null
     */
    public function getLastSerialized(): ?array
    {
        $envelope = $this->getLastEnvelope();

        if ($envelope === null) {
            return null;
        }

        return $this->serializer->encode($envelope);
    }

    /**
     * Clear all stored envelopes.
     */
    public function clear(): void
    {
        $this->sent = [];
        $this->acknowledged = [];
        $this->rejected = [];
    }

    /**
     * Count sent envelopes.
     */
    public function count(): int
    {
        return count($this->sent);
    }

    /**
     * Get acknowledged envelopes.
     *
     * @return array<Envelope>
     */
    public function getAcknowledged(): array
    {
        return $this->acknowledged;
    }

    /**
     * Get rejected envelopes.
     *
     * @return array<Envelope>
     */
    public function getRejected(): array
    {
        return $this->rejected;
    }
}
