<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Native;

use JsonSerializable;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class Producer extends Broker
{
    public function produce(
        JsonSerializable $message,
        string $routingKey,
        string $exchangeName = '',
    ): void {
        if (!$this->connection) {
            $this->connect();
        }

        $headers = new AMQPTable([
            'type' => get_class($message)
        ]);

        $msg = new AMQPMessage(
            json_encode($message),
            [
                'application_headers' => $headers,
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'content_type' => 'application/json',
            ]

        );
        $this->channel->basic_publish($msg, $exchangeName, $routingKey);
    }
}
