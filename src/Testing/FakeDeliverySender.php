<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\Webhook\DeliveryPair;
use Freyr\MessageBroker\Webhook\DeliveryResult;
use Freyr\MessageBroker\Webhook\DeliverySender;

/**
 * Scripted DeliverySender: records each call and returns the result of
 * $respond for each pair (by default: delivered, with a request time of 1 ms).
 * It sends nothing.
 */
final class FakeDeliverySender implements DeliverySender
{
    /** @var list<non-empty-list<DeliveryPair>> the pairs of each send() call */
    public array $calls = [];

    /** @var (callable(DeliveryPair): DeliveryResult)|null the result for one pair */
    public $respond;

    /** @var (callable(): void)|null called at the start of each send() */
    public $onSend;

    public function __construct(
        private readonly int $maxSendMs = 1_000,
    ) {}

    public function send(array $pairs): array
    {
        if ($this->onSend !== null) {
            ($this->onSend)();
        }
        $this->calls[] = $pairs;

        $results = [];
        foreach ($pairs as $pair) {
            $results[$pair->claimed->delivery->id] = $this->respond !== null
                ? ($this->respond)($pair)
                : DeliveryResult::delivered(1);
        }

        return $results;
    }

    public function maxSendMs(): int
    {
        return $this->maxSendMs;
    }

    /** @return list<string> the delivery row ids of all calls, in the order of the calls */
    public function sentIds(): array
    {
        $ids = [];
        foreach ($this->calls as $pairs) {
            foreach ($pairs as $pair) {
                $ids[] = $pair->claimed->delivery->id;
            }
        }

        return $ids;
    }
}
