<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp;

use Freyr\MessageBroker\Consumer\Delivery;
use Freyr\MessageBroker\Consumer\DeliveryProcessor;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Long-running AMQP worker: the thin loop around the DeliveryProcessor. It
 * owns the channel (qos, consume, wait, cancel), the signals, the idle
 * timeout and the message limit. It maps each AMQPMessage to a Delivery and
 * gives the processor an AmqpDeliveryControl for it.
 *
 * The exactly-once algorithm (deserialize, transaction, dedup, dispatch,
 * retry, dead letter) is in DeliveryProcessor. The retry republish to a TTL
 * wait queue is in WaitQueuePublisher.
 */
final class AmqpConsumer
{
    private const string DELIVERY_COUNT_HEADER = 'x-delivery-count';

    private bool $shouldStop = false;

    private int $handled = 0;

    private readonly WaitQueuePublisher $waitQueues;

    public function __construct(
        private readonly AMQPChannel $channel,
        private readonly AmqpQueueConfig $queue,
        private readonly DeliveryProcessor $processor,
        int $confirmTimeoutSec = 5,
    ) {
        $this->waitQueues = new WaitQueuePublisher($channel, $queue->queue, $confirmTimeoutSec);
    }

    /**
     * Blocks until stopped (SIGTERM/SIGINT via pcntl), the optional message
     * limit is reached, or — when an idle timeout is set — the queue stays
     * silent for that long. Limit and timeout exist for tests and batch-style
     * operation; production workers run with neither.
     */
    public function run(?int $messageLimit = null, ?int $idleTimeoutSec = null): void
    {
        $this->registerSignalHandlers();
        $this->channel->basic_qos(0, $this->queue->prefetch, false);
        $consumerTag = $this->channel->basic_consume(queue: $this->queue->queue, callback: $this->handle(...));

        while (!$this->shouldStop && $this->channel->is_consuming()) {
            if ($messageLimit !== null && $this->handled >= $messageLimit) {
                break;
            }

            try {
                $this->channel->wait(timeout: $idleTimeoutSec ?? 0);
            } catch (AMQPTimeoutException) {
                break; // queue stayed silent for the whole idle window
            }
        }

        // Transient deserialize failures intentionally propagate past this
        // cancel; the unacked delivery is requeued on connection close —
        // do not "fix" with a finally.
        $this->channel->basic_cancel($consumerTag);
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    private function handle(AMQPMessage $message): void
    {
        $this->processor->process(
            $this->deliveryFrom($message),
            new AmqpDeliveryControl($message, $this->waitQueues),
        );

        // process() returns only after it acked the delivery; a failure that
        // stops the process throws instead, so the counter never runs ahead.
        ++$this->handled;
    }

    /**
     * The mapping AMQPMessage → Delivery. Public so that a test can assert the
     * mapping directly; the loop is its only caller.
     *
     * The attempt is the x-attempt header when it is an int (at least 1), else 1.
     * The delivery count is the x-delivery-count header (quorum queues) when it
     * is an int, else null.
     *
     * @internal
     */
    public function deliveryFrom(AMQPMessage $message): Delivery
    {
        /** @var array<string, mixed> $properties */
        $properties = $message->get_properties();
        $headers = $properties['application_headers'] ?? null;
        /** @var array<string, mixed> $applicationHeaders */
        $applicationHeaders = $headers instanceof AMQPTable ? $headers->getNativeData() : [];

        $attempt = $applicationHeaders[WaitQueuePublisher::ATTEMPT_HEADER] ?? null;
        $deliveryCount = $applicationHeaders[self::DELIVERY_COUNT_HEADER] ?? null;
        $messageId = $properties['message_id'] ?? null;

        return new Delivery(
            body: $message->getBody(),
            headers: $applicationHeaders,
            attempt: is_int($attempt) ? max(1, $attempt) : 1,
            source: $this->queue->queue,
            fallbackMessageId: is_string($messageId) ? $messageId : null,
            deliveryCount: is_int($deliveryCount) ? $deliveryCount : null,
        );
    }

    private function registerSignalHandlers(): void
    {
        if (!extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->stop());
        pcntl_signal(SIGINT, fn () => $this->stop());
    }
}
