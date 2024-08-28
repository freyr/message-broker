<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Native;

class Configurator extends Broker
{

    public function initialize()
    {
        $this->connect();
        $this->purge();
    }
    public function createExchange(string $exchange, string $type): void
    {
        $this->channel->exchange_declare($exchange, $type, durable: true);
        $this->logger->info('exchange created');
    }

    public function createQueue(string $queue): void
    {
        $this->channel->queue_declare($queue, durable: true);
        $this->logger->info('queue created');
    }

    public function bindQueueToExchange(string $queue, string $exchange, string $bindingKey): void
    {
        $this->channel->queue_bind($queue, $exchange, $bindingKey);
        $this->logger->info('queue bind to exchange');
    }

    private function purge(): void
    {

    }
}
