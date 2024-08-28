<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Native;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

abstract class Broker
{

    protected ?AMQPStreamConnection $connection;
    protected ?AMQPChannel $channel;
    protected Logger $logger;

    public function __construct(
        readonly private string $host = 'amqp',
        readonly private int $port = 5672,
        readonly private string $user = 'guest',
        readonly private string $passwd = 'guest'
    )
    {
        $this->connection = null;
        $this->channel = null;
        $this->logger = new Logger('app_logger');
        $this->logger->pushHandler(new StreamHandler('php://stdout', Level::Debug));
    }

    public function disconnect(): void
    {
        $this->closeChannel();
        if ($this->connection && $this->connection->isConnected()) {
            $this->connection->close();
            $this->connection = null;
        }
    }
    public function connect(): void
    {
        if (!$this->connection) {
            $this->connection = new AMQPStreamConnection($this->host, $this->port, $this->user, $this->passwd);
            if ($this->channel) {
                $this->closeChannel();
            }
            $this->channel = $this->connection->channel();
        }
        if (!$this->channel) {
            $this->channel = $this->connection->channel();
        }
    }

    private function closeChannel(): void
    {
        if ($this->channel && $this->channel->is_open()) {
            $this->channel->close();
            $this->channel = null;
        }
    }
}
