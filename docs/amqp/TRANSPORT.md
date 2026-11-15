# AMQP transport

This library ships the **AMQP transport only** (RabbitMQ), met on its own
terms. AMQP has its own publisher class, consumer class, and config
vocabulary. The classes are in the namespace
`Freyr\MessageBroker\Adapter\Amqp\`. This document describes the routing, the
config classes, the dedicated channel rule, the publisher and the topology.
The consumer is in [CONSUMER.md](CONSUMER.md). The relay loop that calls the
publisher is in [../core/RELAY.md](../core/RELAY.md).

**AMQP routes by message name and gives no ordering guarantee.** Rows publish
in any order, so consumers must be order-insensitive.

## Routing

The AMQP publisher routes by **message name**: it publishes to a named exchange
using the message's `name` (e.g. `order.placed`) as the routing key. There is
no per-key routing knob.

**An unroutable message is dropped by design.** The publisher publishes without
the `mandatory` flag. When no queue binding matches the message name, the
broker drops the message and confirms it, and the relay marks the row
published. A message that nobody subscribes to is not a failure. Bind the
queues before the producers send messages that must arrive.

## Config classes

```php
use Freyr\MessageBroker\Adapter\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Adapter\Amqp\AmqpQueueConfig;

$publish = new AmqpPublishConfig(exchange: 'orders', publisherConfirms: true);
$queue = new AmqpQueueConfig(queue: 'orders_q', prefetch: 32);
```

- `AmqpPublishConfig(string $exchange, bool $publisherConfirms = true)` — the
  exchange must be non-empty (the default `''` exchange is not a supported
  target); `publisherConfirms` enables `confirm_select()` + one confirm wait
  per published batch.
- `AmqpQueueConfig(string $queue, int $prefetch = 32)` — the queue must be
  non-empty; `prefetch` (0 = unlimited) sets `basic_qos` for the consumer.

Both classes throw an `InvalidArgumentException` for an empty exchange or
queue. `AmqpQueueConfig` also throws it for a negative `prefetch`.

## Dedicated channel requirement

Publisher-confirm mode is **channel-global state**: any publisher sharing
a relay's channel corrupts its confirm bookkeeping. Every relay process (and
every relay worker) must own its `AMQPChannel` exclusively — one
relay process, one dedicated channel.

## The publisher

`AmqpMessagePublisher` implements the core port `OutboxPublisher`. Its
constructor arguments are `amqp` (the channel), `publish` (an
`AmqpPublishConfig`), `contentType` and `confirmTimeoutSec = 5`.

```php
use Freyr\MessageBroker\Adapter\Amqp\AmqpMessagePublisher;
use Freyr\MessageBroker\Adapter\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Serializer\JsonWireFormat;

$publisher = new AmqpMessagePublisher(
    $channel,                                  // a dedicated PhpAmqpLib AMQPChannel
    new AmqpPublishConfig(exchange: 'orders'),
    JsonWireFormat::CONTENT_TYPE,
    confirmTimeoutSec: 5,
);
```

The publisher builds the `x-message-id`, `x-message-name` and `x-created-at` AMQP
headers from the `id`, `message_name` and `created_at` columns of the row. The
produce-time headers of the row travel with them, and the three envelope
headers win on a key collision. The publisher sets the message id of AMQP to
the row id, the content type to the `contentType` argument and the delivery
mode to persistent.

With `publisherConfirms: true` (the default), the publisher puts the channel in
confirm mode at the first publish. It publishes all rows of a batch, then makes
one confirm wait for the whole batch (`confirmTimeoutSec`). It returns the ids
of the rows that the broker nacked. The relay backs off these rows only. A
channel or connection failure, or a confirm timeout, throws; the relay then
backs off the rows of the call and stops the worker (see
[../core/RELAY.md](../core/RELAY.md), *Failure and crash semantics*). With
`publisherConfirms: false`, the publisher makes no confirm wait and returns no
nacked ids.

The AMQP publisher has no batch limit: `maxBatchSize()` returns `PHP_INT_MAX`,
so one claim is one chunk and one confirm wait.

## Topology

The library declares one kind of queue only: the TTL wait queues of the
consumer retry (see [CONSUMER.md](CONSUMER.md)). You declare the exchange, the
work queue and the bindings, for example with your infrastructure
tooling. The routing key is the message name, so use an exchange type that
routes by key, for example `direct` or `topic`. Bind each work queue to the
message names that its consumers handle. Declare the work queue with a
dead-letter exchange (see [CONSUMER.md](CONSUMER.md), *Dead-letter exchange of
the work queue*).
