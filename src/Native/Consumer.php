<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Native;

use Freyr\MessageBroker\Message\MessageHandler;
use Throwable;

class Consumer extends Broker
{

    public function batchConsume(MessageHandler $handler, string ...$queues)
    {
        if (!$this->connection) {
            $this->connect();
        }
        $this->channel->basic_qos(0, 1, false);

        foreach ($queues as $queue) {
            $this->channel->basic_consume(queue: $queue, callback: $handler);
        }

        // Enter a loop waiting for incoming messages
        while ($this->channel->is_consuming()) {
            $this->channel->wait();
        }

        $this->disconnect();
    }
    public function consume(string $queueName, MessageHandler $handler): void
    {
        if (!$this->connection) {
            $this->connect();
        }
        $this->channel->basic_qos(0, 1, false);
        $this->channel->basic_consume(queue: $queueName, callback: $handler);

        try {
            $this->channel->consume();
        } catch (Throwable $exception) {
            throw $exception;
        }

        $this->disconnect();
    }
}
