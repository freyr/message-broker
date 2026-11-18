<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Amqp;

use Freyr\MessageBroker\Consumer\Delivery;
use Freyr\MessageBroker\Consumer\DeliveryProcessor;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Long-running AMQP worker: the thin loop around the DeliveryProcessor. It
 * owns the channel (qos, consume, wait, cancel), the signals, the idle
 * timeout and the message limit. It maps each AMQPMessage to a Delivery and
 * gives the processor an AmqpDeliveryControl for it.
 *
 * The exactly-once algorithm (deserialize, transaction, dedup, dispatch,
 * retry, dead letter) is in DeliveryProcessor. The retry republish to a TTL
 * wait queue is in WaitQueuePublisher.
 *
 * The return rule: at each stop that it controls, the consumer returns its
 * unacked deliveries itself, so that the broker delivery count
 * (x-delivery-count) counts only the delivery that was in work.
 */
final class AmqpConsumer
{
    private bool $shouldStop = false;

    private int $handled = 0;

    /** The delivery that the processor works on; null between deliveries. */
    private ?AMQPMessage $inWork = null;

    private readonly WaitQueuePublisher $waitQueues;

    /** @param LoggerInterface $logger receives a failure inside the return of the unacked deliveries */
    public function __construct(
        private readonly AMQPChannel $channel,
        private readonly AmqpQueueConfig $queue,
        private readonly DeliveryProcessor $processor,
        int $confirmTimeoutSec = 5,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->waitQueues = new WaitQueuePublisher($channel, $queue->queue, $confirmTimeoutSec);
    }

    /**
     * Blocks until stopped (SIGTERM/SIGINT via pcntl), the optional message
     * limit is reached, or — when an idle timeout is set — the queue stays
     * silent for that long. Limit and timeout exist for tests and batch-style
     * operation; production workers run with neither.
     *
     * After basic_consume, every stop returns the unacked deliveries:
     *  - a graceful stop (stop(), a signal, the message limit, the idle
     *    timeout): basic.cancel, then one basic.nack (tag 0, multiple,
     *    requeue), which adds no delivery count. A failure of this return is
     *    the error of run().
     *  - a Throwable (from the processor or from the channel): basic.cancel,
     *    basic.reject (requeue) of the delivery in work, which counts, then
     *    the same basic.nack for the others; then the same Throwable goes on.
     *    A failure inside this return is logged at the level error and never
     *    replaces the original Throwable.
     * basic.cancel comes first: else the broker delivers the returned
     * deliveries again to this consumer before the close.
     */
    public function run(?int $messageLimit = null, ?int $idleTimeoutSec = null): void
    {
        $this->registerSignalHandlers();
        $this->channel->basic_qos(0, $this->queue->prefetch, false);
        $consumerTag = $this->channel->basic_consume(queue: $this->queue->queue, callback: $this->handle(...));

        try {
            while (!$this->shouldStop && $this->channel->is_consuming()) {
                if ($messageLimit !== null && $this->handled >= $messageLimit) {
                    break;
                }

                try {
                    $this->channel->wait(timeout: $idleTimeoutSec ?? 0);
                } catch (AMQPTimeoutException $timeout) {
                    if ($this->inWork !== null) {
                        // A confirm timeout of the retry republish, from inside
                        // the processor: a failure of the delivery, not silence.
                        throw $timeout;
                    }

                    break; // queue stayed silent for the whole idle window
                }
            }
        } catch (Throwable $error) {
            $this->returnAfterFailure($consumerTag, $error);

            throw $error;
        }

        $this->channel->basic_cancel($consumerTag);
        $this->channel->basic_nack(0, true, true);
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    private function handle(AMQPMessage $message): void
    {
        $this->inWork = $message;
        $this->processor->process(
            $this->deliveryFrom($message),
            new AmqpDeliveryControl($message, $this->waitQueues),
        );
        $this->inWork = null;

        // process() returns only after it acked the delivery; a failure that
        // stops the process throws instead, so the counter never runs ahead.
        ++$this->handled;
    }

    private function returnAfterFailure(string $consumerTag, Throwable $error): void
    {
        $inWork = $this->inWork;
        $this->inWork = null;

        try {
            $this->channel->basic_cancel($consumerTag);
            if ($inWork !== null) {
                $this->channel->basic_reject($inWork->getDeliveryTag(), true);
            }
            $this->channel->basic_nack(0, true, true);
        } catch (Throwable $returnFailure) {
            // The original error goes on; the broker returns the unacked
            // deliveries at the close, each with one more delivery count.
            $this->logger->error(
                'AMQP consumer could not return its unacked deliveries; the broker returns them at the close',
                [
                    'queue' => $this->queue->queue,
                    'exception' => $returnFailure,
                ],
            );
        }
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
        $deliveryCount = $applicationHeaders[WaitQueuePublisher::DELIVERY_COUNT_HEADER] ?? null;
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
