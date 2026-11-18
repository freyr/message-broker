<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Amqp;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;

/**
 * Transport-native delay: a durable per-delay wait queue with x-message-ttl
 * that dead-letters back to the work queue through the default exchange. The
 * wait queue is declared lazily, once per delay.
 *
 * The republish is confirmed before it returns, so the caller can ack the
 * original delivery afterwards: an unconfirmed republish could be lost with
 * the broker while the ack is not. A nack or a confirm timeout throws; the
 * original delivery stays unacked and is redelivered with the same attempt.
 *
 * Long-lived: it keeps the confirm mode and the declared queues for the
 * channel.
 */
final class WaitQueuePublisher
{
    public const string ATTEMPT_HEADER = 'x-attempt';

    /**
     * The broker count of deliveries that ended with no ack (quorum queues).
     * The republish drops it, so a wait-queue pass starts the count again.
     */
    public const string DELIVERY_COUNT_HEADER = 'x-delivery-count';

    /** @var list<int> delays (ms) whose wait queue is already declared */
    private array $declaredWaitQueues = [];

    private bool $confirmsEnabled = false;

    private int $republishNacks = 0;

    public function __construct(
        private readonly AMQPChannel $channel,
        private readonly string $queue,
        private readonly int $confirmTimeoutSec = 5,
    ) {}

    public function scheduleRetry(AMQPMessage $delivery, int $delayMs, int $nextAttempt): void
    {
        if (!$this->confirmsEnabled) {
            $this->channel->confirm_select();
            $this->channel->set_nack_handler(function (): void {
                ++$this->republishNacks;
            });
            $this->confirmsEnabled = true;
        }
        $nacksBefore = $this->republishNacks;

        if (!in_array($delayMs, $this->declaredWaitQueues, true)) {
            $this->channel->queue_declare(
                $this->waitQueue($delayMs),
                false,
                true,
                false,
                false,
                false,
                new AMQPTable([
                    'x-message-ttl' => $delayMs,
                    'x-dead-letter-exchange' => '',
                    'x-dead-letter-routing-key' => $this->queue,
                ]),
            );
            $this->declaredWaitQueues[] = $delayMs;
        }

        /** @var array<string, mixed> $properties */
        $properties = $delivery->get_properties();
        $headers = $properties['application_headers'] ?? null;
        /** @var array<string, mixed> $applicationHeaders */
        $applicationHeaders = $headers instanceof AMQPTable ? $headers->getNativeData() : [];
        unset($applicationHeaders[self::DELIVERY_COUNT_HEADER]);
        $properties['application_headers'] = new AMQPTable(
            array_merge($applicationHeaders, [
                self::ATTEMPT_HEADER => $nextAttempt,
            ]),
        );

        $this->channel->basic_publish(
            new AMQPMessage($delivery->getBody(), $properties),
            '',
            $this->waitQueue($delayMs),
        );
        $this->channel->wait_for_pending_acks($this->confirmTimeoutSec);
        if ($this->republishNacks > $nacksBefore) {
            throw new RuntimeException("Broker nacked the retry republish to {$this->waitQueue($delayMs)}");
        }
    }

    private function waitQueue(int $delayMs): string
    {
        return "{$this->queue}.wait.{$delayMs}";
    }
}
