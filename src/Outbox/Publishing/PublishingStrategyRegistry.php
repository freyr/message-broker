<?php

declare(strict_types=1);

namespace Freyr\Messenger\Outbox\Publishing;

use Psr\Log\LoggerInterface;

/**
 * Publishing Strategy Registry.
 *
 * Manages a collection of publishing strategies and finds the appropriate one for each event.
 */
final readonly class PublishingStrategyRegistry
{
    /**
     * @param iterable<PublishingStrategyInterface> $strategies
     */
    public function __construct(
        private iterable $strategies,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Find a strategy that supports the given event.
     *
     * @param object $event Domain event
     * @return PublishingStrategyInterface|null Strategy if found, null otherwise
     */
    public function findStrategyFor(object $event): ?PublishingStrategyInterface
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($event)) {
                $this->logger->debug('Found publishing strategy for event', [
                    'event_class' => $event::class,
                    'strategy' => $strategy->getName(),
                ]);

                return $strategy;
            }
        }

        $this->logger->warning('No publishing strategy found for event', [
            'event_class' => $event::class,
        ]);

        return null;
    }

    /**
     * Get all registered strategies.
     *
     * @return list<PublishingStrategyInterface>
     */
    public function getAllStrategies(): array
    {
        return $this->strategies instanceof \Traversable
            ? iterator_to_array($this->strategies, false)
            : (array) $this->strategies;
    }
}
