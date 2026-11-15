# AMQP consumer

`AmqpConsumer` is the AMQP loop around the core `DeliveryProcessor`. It owns the
channel (qos, consume, wait, cancel), the signals, the idle timeout and the
message limit. It maps each `AMQPMessage` to a `Delivery` and gives the
processor an `AmqpDeliveryControl` for it. The exactly-once algorithm and the
retry policy are in [../core/CONSUMER.md](../core/CONSUMER.md). The routing and
the topology are in [TRANSPORT.md](TRANSPORT.md).

## Wiring

```php
use Freyr\MessageBroker\Adapter\Amqp\AmqpConsumer;
use Freyr\MessageBroker\Adapter\Amqp\AmqpQueueConfig;

$consumer = new AmqpConsumer(
    channel: $channel,
    queue: new AmqpQueueConfig('orders_q', prefetch: 32),
    processor: $processor,  // a DeliveryProcessor, see docs/QUICKSTART.md
    confirmTimeoutSec: 5,
);
$consumer->run(); // blocks
```

The constructor arguments are `channel`, `queue` (an `AmqpQueueConfig`),
`processor` and `confirmTimeoutSec = 5`. A complete example, with the
`DeliveryProcessor`, is in [../QUICKSTART.md](../QUICKSTART.md).

`run()` sets `basic_qos` to the `prefetch` of the queue config and consumes
until it stops. It stops on SIGTERM or SIGINT when `ext-pcntl` is loaded. For
tests and batch-style operation, `run()` accepts `messageLimit` and
`idleTimeoutSec`.

## Delivery mapping

The consumer builds the `Delivery` from the `AMQPMessage`. The attempt is the
`x-attempt` header when it is an integer (at least 1), otherwise 1. The
delivery count is the `x-delivery-count` header (quorum queues) when it is an
integer, otherwise `null`. The source is the name of the queue.

## Retry with TTL wait queues

Consumer-side retry uses a durable per-delay wait queue with a
`x-message-ttl` that dead-letters back to the work queue through the default
exchange (transport-native delay), rather than an in-process sleep. The queue
is named `<work queue>.wait.<delay in ms>`. The consumer declares it at the
first retry with that delay. The retry policy is in
[../core/CONSUMER.md](../core/CONSUMER.md).

The consumer puts its channel in publisher-confirm mode at the first retry. It
acks the original delivery only after the broker confirms the republish. A
nack or a confirm timeout stops the process, and the original delivery is
redelivered with the same attempt.

A database failure stops the consumer in the same way, and the delivery is
redelivered (see [../core/CONSUMER.md](../core/CONSUMER.md), *Database
failures*).

## Dead-letter exchange of the work queue

**Set a dead-letter exchange on the work queue.** The consumer stops, and
RabbitMQ redelivers, when a message cannot be decoded for a temporary reason
or when the database fails. A message that always causes this, or a handler
that ends the PHP process (for example, out of memory), comes back again and
again. A quorum queue drops such a message after its delivery limit (20 by
default in RabbitMQ 4) when the queue has no dead-letter exchange. A classic
queue redelivers it without a limit. Declare the work queue with
`x-dead-letter-exchange` (and, for a quorum queue, a `x-delivery-limit` that
you choose), and monitor the dead-letter queue.

## Sequential consumers over one connection

**Running `AmqpConsumer` sequentially over one connection.** `run()` accepts
`messageLimit`/`idleTimeoutSec` — these exist for tests and batch-style
operation; production workers normally run with neither, blocking forever.
If you do run several consumers sequentially over one shared AMQP
connection (e.g. a batch job draining multiple queues in turn), close the
previous consumer's channel before starting the next one: prefetched-but-
unacked deliveries are requeued only on channel close, not automatically
between successive `run()` calls.
