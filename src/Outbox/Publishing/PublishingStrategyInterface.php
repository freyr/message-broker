<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\Publishing;

/**
 * Publishing Strategy Interface.
 *
 * Defines how to publish messages from the outbox to external systems.
 * Each strategy can handle specific message types and publish them to different targets.
 */
interface PublishingStrategyInterface
{
    /**
     * Check if this strategy can handle the given event.
     *
     * @param object $event Domain event to check
     */
    public function supports(object $event): bool;

    /**
     * Publish the event to the target system.
     *
     * @param object $event Domain event to publish
     * @throws \RuntimeException If publishing fails
     */
    public function publish(object $event): void;

    /**
     * Get strategy name for logging/debugging.
     */
    public function getName(): string;
}
