# freyr/message-broker (core)

This package is the core of the Freyr Message Broker library. It is not
published separately yet. Today the repository `freyr/message-broker` holds the
code of the core (`src/broker/`) and of both adapters, and the links below point
to the documents in this repository.

The core holds the ports (interfaces), the relay loop, the delivery processor
with its exactly-once algorithm, the retry policy, dead letters and replay, the
JSON wire format, the console commands and the test kit. It has no `PDO` and no
`PhpAmqpLib` in its code. A storage adapter and a transport adapter implement
its ports.

## Requirements

- PHP 8.4 or later, with `ext-mbstring`
- `psr/log` ^3.0, `symfony/clock` ^8.1, `symfony/console` ^7.4 or ^8.1,
  `symfony/uid` ^7.4 or ^8.1
- `phpunit/phpunit` for the test kit (`Freyr\MessageBroker\Testing\`), as a
  development dependency

## Install

After the split, install the core and the adapters that you use:

```bash
composer require freyr/message-broker
composer require freyr/message-broker-storage-postgresql freyr/message-broker-transport-amqp
```

Today, `composer require freyr/message-broker` installs the code of all three
packages.

## Minimal wiring

```php
use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Outbox\Relay;
use Freyr\MessageBroker\Serializer\JsonWireFormat;

// $outboxStore implements OutboxStore, for example PostgreSqlOutboxStore.
// $publisher implements OutboxPublisher, for example AmqpMessagePublisher.
$producer = new OutboxProducer($outboxStore, new JsonWireFormat(), lane: 'orders');
$relay = new Relay(outbox: $outboxStore, publisher: $publisher, lane: 'orders');
$relay->run(); // blocks; SIGTERM or SIGINT stops it
```

## Documents

- [Quickstart](../../docs/QUICKSTART.md): produce, relay and consume end to end
- [Relay and lanes](../../docs/RELAY.md)
- [Delivery processor and retry](../../docs/CONSUMER.md)
- [Ports](../../docs/PORTS.md)
- [CLI reference](../../docs/CLI_REFERENCE.md)
- [DLQ operations](../../docs/DLQ_OPERATIONS.md)
- [Production](../../docs/PRODUCTION.md)
- [Testing](../../docs/TESTING.md)
