<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Amqp;

use DateTimeInterface;
use Freyr\MessageBroker\DeadLetter\DeadLetterImporter;
use Freyr\MessageBroker\DeadLetter\TransportMove;
use PhpAmqpLib\Channel\AMQPChannel;
use Throwable;

/**
 * Imports the messages of one AMQP dead letter queue into the dead letter
 * store: basic.get, read the TransportMove from x-death, DeadLetterImporter,
 * then basic.ack. A batch: run() ends when the queue is empty or the limit is
 * reached.
 *
 * The move is the newest x-death entry (the broker puts the newest first)
 * whose reason is not 'expired': the 'expired' entries come from the wait
 * queues, whose messages return to the work queue. An entry needs a string
 * queue, a string reason and an int count; an entry that does not have them is
 * skipped. The time of the entry (a \DateTime with second precision) becomes
 * epoch milliseconds. With no such entry the move is null.
 *
 * The ack comes only after the store returns. When the store throws, the
 * message returns with basic.nack and requeue (no delivery count on a quorum
 * queue), and run() throws the same error. When the nack fails too, the store
 * error still goes on; the channel close then returns the message.
 */
final readonly class AmqpDeadLetterImport
{
    private const string DEATH_HEADER = 'x-death';

    private const string EXPIRED = 'expired';

    private AmqpDeliveryMapper $mapper;

    public function __construct(
        private AMQPChannel $channel,
        private string $deadLetterQueue,
        private DeadLetterImporter $importer,
    ) {
        $this->mapper = new AmqpDeliveryMapper($deadLetterQueue);
    }

    /**
     * @param int|null $limit the maximum number of messages in this run; null: until the queue is empty
     *
     * @return int the number of imported messages
     *
     * @throws Throwable the error of the store or of the channel
     */
    public function run(?int $limit = null): int
    {
        $imported = 0;
        while ($limit === null || $imported < $limit) {
            $message = $this->channel->basic_get($this->deadLetterQueue);
            if ($message === null) {
                break;
            }

            $delivery = $this->mapper->map($message);
            try {
                $this->importer->import($delivery, self::moveFrom($delivery->headers));
            } catch (Throwable $error) {
                try {
                    $this->channel->basic_nack($message->getDeliveryTag(), false, true);
                } catch (Throwable) {
                    // The store error goes on; the channel close returns the message.
                }

                throw $error;
            }
            $this->channel->basic_ack($message->getDeliveryTag());
            ++$imported;
        }

        return $imported;
    }

    /** @param array<string, mixed> $headers */
    private static function moveFrom(array $headers): ?TransportMove
    {
        $deaths = $headers[self::DEATH_HEADER] ?? null;
        if (!is_array($deaths)) {
            return null;
        }

        foreach ($deaths as $death) {
            if (!is_array($death)) {
                continue;
            }
            $queue = $death['queue'] ?? null;
            $reason = $death['reason'] ?? null;
            $count = $death['count'] ?? null;
            if (!is_string($queue) || !is_string($reason) || !is_int($count) || $reason === self::EXPIRED) {
                continue;
            }
            $time = $death['time'] ?? null;

            return new TransportMove(
                sourceQueue: $queue,
                reason: $reason,
                count: $count,
                movedAt: $time instanceof DateTimeInterface ? $time->getTimestamp() * 1000 : null,
            );
        }

        return null;
    }
}
