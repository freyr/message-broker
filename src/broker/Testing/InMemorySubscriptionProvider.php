<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\Webhook\Subscription;
use Freyr\MessageBroker\Webhook\SubscriptionProvider;

/**
 * In-memory SubscriptionProvider. add() registers a subscription with the
 * message names that it receives; remove() takes it away, as an application
 * that removes a subscription. lanes() counts its calls, so a test can see the
 * refresh of the delivery loop, and find() records its arguments.
 */
final class InMemorySubscriptionProvider implements SubscriptionProvider
{
    public int $lanesCalls = 0;

    /** @var list<string> the argument of each find() call */
    public array $finds = [];

    /** @var array<string, Subscription> subscription id => subscription */
    private array $subscriptions = [];

    /** @var array<string, list<string>> subscription id => message names */
    private array $messageNames = [];

    /** Register (or replace) a subscription that receives the given message names. */
    public function add(Subscription $subscription, string ...$messageNames): void
    {
        $this->subscriptions[$subscription->id] = $subscription;
        $this->messageNames[$subscription->id] = array_values($messageNames);
    }

    public function remove(string $id): void
    {
        unset($this->subscriptions[$id], $this->messageNames[$id]);
    }

    public function forMessage(string $messageName): array
    {
        return array_values(array_filter(
            $this->subscriptions,
            fn (Subscription $s): bool => in_array($messageName, $this->messageNames[$s->id], true),
        ));
    }

    public function find(string $lane): ?Subscription
    {
        $this->finds[] = $lane;

        return $this->subscriptions[$lane] ?? null;
    }

    public function lanes(): array
    {
        ++$this->lanesCalls;

        return array_map('strval', array_keys($this->subscriptions));
    }
}
