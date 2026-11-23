# freyr/message-broker-transport-amqp

This package is the AMQP (RabbitMQ) transport adapter of the Freyr Message Broker library,
namespace `Freyr\MessageBroker\Transport\Amqp\`.

> The repository `freyr/message-broker-transport-amqp` is a read-only copy of the folder
> `transport/amqp/` of [freyr/message-broker](https://github.com/freyr/message-broker).
> Development, issues and pull requests are in freyr/message-broker. A
> release has the tag `transport-amqp-v<version>` in freyr/message-broker
> and the tag `v<version>` in `freyr/message-broker-transport-amqp`.

The adapter holds the publisher that sends outbox rows to an exchange, the
consumer loop that gives each delivery to the core delivery processor, the
TTL wait queues of the retry, and the config classes. It routes by message
name and gives no ordering guarantee.

## Requirements

- PHP 8.4 or later
- `freyr/message-broker` ^0.15 (the core)
- `php-amqplib/php-amqplib` ^3.7.2
- `psr/log` ^3.0
- A storage adapter for the outbox, deduplication and dead letters, for example
  `freyr/message-broker-storage-postgresql`

## Install

```bash
composer require freyr/message-broker-transport-amqp
```

## Minimal wiring

```php
use Freyr\MessageBroker\Transport\Amqp\AmqpConsumer;
use Freyr\MessageBroker\Transport\Amqp\AmqpMessagePublisher;
use Freyr\MessageBroker\Transport\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpQueueConfig;
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

- [Transport](docs/TRANSPORT.md): routing, config classes, dedicated channel, publisher, topology
- [Consumer](docs/CONSUMER.md): TTL wait queues, dead-letter exchange
- [Dead letters](docs/DEAD_LETTERS.md): the queue sink (`AmqpDeadLetterSink`), the import of a dead letter queue, the read of `x-death`, the permissions
- [Quickstart](https://github.com/freyr/message-broker/blob/main/docs/QUICKSTART.md): a complete example with all three packages
- [Delivery processor and retry](https://github.com/freyr/message-broker/blob/main/docs/CONSUMER.md)
