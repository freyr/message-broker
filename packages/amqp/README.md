# freyr/message-broker-amqp

This package is the AMQP (RabbitMQ) transport adapter of the Freyr Message
Broker library. It is not published separately yet. Today the repository
`freyr/message-broker` holds its code in `src/adapter/amqp/`, and the links
below point to the documents in this repository.

The adapter holds the publisher that sends outbox rows to an exchange, the
consumer loop that gives each delivery to the core delivery processor, the
TTL wait queues of the retry, and the config classes. It routes by message
name and gives no ordering guarantee.

## Requirements

- PHP 8.4 or later
- `freyr/message-broker` ^0.9 (the core)
- `php-amqplib/php-amqplib` ^3.7.2
- `psr/log` ^3.0
- A storage adapter for the outbox, deduplication and dead letters, for example
  `freyr/message-broker-postgresql`

## Install

After the split:

```bash
composer require freyr/message-broker-amqp
```

Today, `composer require freyr/message-broker` installs this code with the
core, and you add `php-amqplib/php-amqplib` yourself.

## Minimal wiring

```php
use Freyr\MessageBroker\Adapter\Amqp\AmqpConsumer;
use Freyr\MessageBroker\Adapter\Amqp\AmqpMessagePublisher;
use Freyr\MessageBroker\Adapter\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Adapter\Amqp\AmqpQueueConfig;
use Freyr\MessageBroker\Outbox\Relay;
use Freyr\MessageBroker\Serializer\JsonWireFormat;

// Each relay worker and each consumer owns its own dedicated AMQPChannel.
$publisher = new AmqpMessagePublisher(
    $relayChannel,
    new AmqpPublishConfig(exchange: 'orders'),
    JsonWireFormat::CONTENT_TYPE,
);
$relay = new Relay(outbox: $outboxStore, publisher: $publisher, lane: 'orders');

// $processor is a DeliveryProcessor of the core.
$consumer = new AmqpConsumer(
    channel: $consumerChannel,
    queue: new AmqpQueueConfig('orders_q'),
    processor: $processor,
);
```

## Documents

- [Transport](../../docs/amqp/TRANSPORT.md): routing, config classes, dedicated channel, publisher, topology
- [Consumer](../../docs/amqp/CONSUMER.md): TTL wait queues, dead-letter exchange
- [Quickstart](../../docs/QUICKSTART.md): a complete example with all three packages
- [Delivery processor and retry](../../docs/core/CONSUMER.md)
