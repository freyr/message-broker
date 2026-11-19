<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Amqp;

use Freyr\MessageBroker\BrokerHeader;
use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterHeader;
use Freyr\MessageBroker\DeadLetter\DeadLetterSink;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;

/**
 * A dead letter sink that publishes each dead letter as a new persistent
 * message to the configured exchange and routing key (the dead letter queue)
 * and returns only after the publisher confirm:
 *
 *  - body: the body of the dead letter (the canonical document, or the raw
 *    bytes of a malformed message);
 *  - headers: the headers of the dead letter, then x-dead-letter
 *    ({@see DeadLetterHeader}), which wins on a key collision;
 *  - message_id property: the message id of the dead letter.
 *
 * The publish has the mandatory flag: with no matching binding the broker
 * returns the message (and still confirms it), and store() throws. A nack, a
 * return or a confirm timeout throws, so the consumer does not ack the
 * delivery. message-broker:dlq:import moves the messages of the dead letter
 * queue into the dead_letters table.
 *
 * The channel must be one that no other publisher uses: the sink puts it in
 * confirm mode and sets its nack handler and return listener at the first
 * store(), and a channel has one of each (WaitQueuePublisher keeps its own
 * nack handler on the consumer channel). A second channel on the connection of
 * the consumer is sufficient. The sink declares no exchange and no queue.
 */
final class AmqpDeadLetterSink implements DeadLetterSink
{
    private bool $confirmMode = false;

    private ?AMQPMessage $current = null;

    private bool $nacked = false;

    private ?string $returned = null;

    public function __construct(
        private readonly AMQPChannel $channel,
        private readonly AmqpDeadLetterConfig $config,
    ) {}

    /**
     * @throws RuntimeException     when the broker nacks or returns the message
     * @throws AMQPTimeoutException when the confirm does not arrive in time
     */
    public function store(DeadLetter $deadLetter): void
    {
        if (!$this->confirmMode) {
            $this->channel->confirm_select();
            $this->channel->set_nack_handler(function (AMQPMessage $message): void {
                // A late nack of an earlier publish (its confirm timed out) is not the nack of this one.
                if ($message === $this->current) {
                    $this->nacked = true;
                }
            });
            $this->channel->set_return_listener(function (int $replyCode, string $replyText): void {
                $this->returned = "{$replyCode} {$replyText}";
            });
            $this->confirmMode = true;
        }

        $headers = $deadLetter->headers;
        $headers[BrokerHeader::DEAD_LETTER] = DeadLetterHeader::encode($deadLetter);
        $message = new AMQPMessage($deadLetter->body, [
            'application_headers' => new AMQPTable($headers),
            'message_id' => $deadLetter->messageId,
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ]);
        $this->track($message);

        $this->channel->basic_publish($message, $this->config->exchange, $this->config->routingKey, true);
        $this->channel->wait_for_pending_acks_returns($this->config->confirmTimeoutSec);

        $target = "the dead letter {$deadLetter->id} to exchange '{$this->config->exchange}', routing key '{$this->config->routingKey}'";
        if ($this->returned !== null) {
            throw new RuntimeException("The broker returned {$target}: {$this->returned}");
        }
        if ($this->nacked) {
            throw new RuntimeException("The broker nacked {$target}");
        }
    }

    /** The handlers report a nack or a return of this message only. */
    private function track(AMQPMessage $message): void
    {
        $this->current = $message;
        $this->nacked = false;
        $this->returned = null;
    }
}
