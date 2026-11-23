<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp;

use Freyr\MessageBroker\Consumer\Delivery;
use Freyr\MessageBroker\Consumer\DeliveryProcessor;
use Freyr\MessageBroker\Worker\WorkerLimitGuard;
use Freyr\MessageBroker\Worker\WorkerLimits;
use Freyr\MessageBroker\Worker\WorkerStopReason;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Long-running AMQP worker: the thin loop around the DeliveryProcessor. It
 * owns the channel (qos, consume, wait, cancel), the signals, the idle
 * timeout and the worker limits. It maps each AMQPMessage to a Delivery and
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

    /** Counts the deliveries and the handler failures of the current run. */
    private WorkerLimitGuard $guard;

    /** The delivery that the processor works on; null between deliveries. */
    private ?AMQPMessage $inWork = null;

    private readonly WaitQueuePublisher $waitQueues;

    private readonly AmqpDeliveryMapper $mapper;

    /** @param LoggerInterface $logger receives a failure inside the return of the unacked deliveries, and the line of a limit stop */
    public function __construct(
        private readonly AMQPChannel $channel,
        private readonly AmqpQueueConfig $queue,
        private readonly DeliveryProcessor $processor,
        int $confirmTimeoutSec = 5,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->waitQueues = new WaitQueuePublisher($channel, $queue->queue, $confirmTimeoutSec);
        $this->mapper = new AmqpDeliveryMapper($queue->queue);
        $this->guard = new WorkerLimitGuard(new WorkerLimits());
    }

    /**
     * Blocks until stopped (SIGTERM/SIGINT via pcntl), a limit of
     * {@see WorkerLimits} is reached, or — when an idle timeout is set — the
     * queue stays silent for that long. The idle timeout exists for tests and
     * batch-style operation; an idle timeout of 0 or null is no idle timeout.
     *
     * A limit is checked after each delivery (after process() returned, so
     * after the commit and the ack) and after each wait with no delivery. A
     * limit stop logs one info line and calls {@see stop()}, as a signal
     * does. With a time limit, no wait is longer than the time that is left:
     * the wait timeout is the time left when there is no idle timeout or when
     * the time left is not larger than the idle timeout, else the idle
     * timeout. A timeout of a wait with no delivery in work is a time limit
     * stop when the time left gave the timeout, else an idle stop.
     *
     * After basic_consume, every stop returns the unacked deliveries:
     *  - a graceful stop (stop(), a signal, a worker limit, the idle
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
    public function run(?WorkerLimits $limits = null, ?int $idleTimeoutSec = null): void
    {
        $this->guard = new WorkerLimitGuard($limits ?? new WorkerLimits());
        $this->guard->start();
        $this->registerSignalHandlers();
        $this->channel->basic_qos(0, $this->queue->prefetch, false);
        $consumerTag = $this->channel->basic_consume(queue: $this->queue->queue, callback: $this->handle(...));
        $idleTimeout = $idleTimeoutSec === 0 ? null : $idleTimeoutSec;

        try {
            while (!$this->shouldStop && $this->channel->is_consuming()) {
                $secondsLeft = $this->guard->secondsLeft();
                if ($secondsLeft === 0.0) {
                    $reason = WorkerStopReason::TimeLimit;
                } else {
                    // One clock read for each wait: the note of the source of the
                    // timeout alone decides the path of an AMQPTimeoutException.
                    $timeoutIsTimeLeft = $secondsLeft !== null
                        && ($idleTimeout === null || $secondsLeft <= $idleTimeout);

                    try {
                        $this->channel->wait(timeout: $timeoutIsTimeLeft ? $secondsLeft : $idleTimeout ?? 0);
                        $reason = $this->guard->stopReason();
                    } catch (AMQPTimeoutException $timeout) {
                        if ($this->inWork !== null) {
                            // A confirm timeout of the retry republish, from inside
                            // the processor: a failure of the delivery, not silence.
                            throw $timeout;
                        }
                        if (!$timeoutIsTimeLeft) {
                            break; // queue stayed silent for the whole idle window
                        }
                        $reason = WorkerStopReason::TimeLimit;
                    }
                }

                if ($reason !== null) {
                    $this->stopAtLimit($reason);
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

    private function stopAtLimit(WorkerStopReason $reason): void
    {
        $this->logger->info('Worker limit reached; the worker stops', [
            'queue' => $this->queue->queue,
            'reason' => $reason->value,
            'messages' => $this->guard->messages(),
            'failures' => $this->guard->failures(),
            'elapsedSeconds' => $this->guard->elapsedMs() / 1_000.0,
            'memoryBytes' => $this->guard->usedMemory(),
        ]);
        $this->stop();
    }

    private function handle(AMQPMessage $message): void
    {
        $this->inWork = $message;
        $outcome = $this->processor->process(
            $this->deliveryFrom($message),
            new AmqpDeliveryControl($message, $this->waitQueues),
        );
        $this->inWork = null;

        // process() returns only after it acked the delivery; a failure that
        // stops the process throws instead, so the counts never run ahead.
        $this->guard->recordMessages(1);
        if ($outcome->isHandlerFailure()) {
            $this->guard->recordFailure();
        }
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
     * The mapping AMQPMessage → Delivery (AmqpDeliveryMapper, with the queue of
     * the consumer as the source). Public so that a test can assert the
     * mapping directly; the loop is its only caller.
     *
     * @internal
     */
    public function deliveryFrom(AMQPMessage $message): Delivery
    {
        return $this->mapper->map($message);
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
