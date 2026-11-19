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
    logger: $logger,        // optional PSR-3 logger
);
$consumer->run(); // blocks
```

The constructor arguments are `channel`, `queue` (an `AmqpQueueConfig`),
`processor`, `confirmTimeoutSec = 5` and `logger` (a PSR-3 `LoggerInterface`,
a `NullLogger` by default). The consumer logs a failure inside the return of
its unacked deliveries (see *The return rule*) to the `logger`. A complete
example, with the `DeliveryProcessor`, is in [../QUICKSTART.md](../QUICKSTART.md).

`run()` sets `basic_qos` to the `prefetch` of the queue config and consumes
until it stops. It stops on SIGTERM or SIGINT when `ext-pcntl` is loaded. For
tests and batch-style operation, `run()` accepts `messageLimit` and
`idleTimeoutSec`.

## Delivery mapping

The consumer builds the `Delivery` from the `AMQPMessage`. The source is the
name of the queue.

- The attempt is the library header `x-attempt` when it is an integer (at
  least 1), otherwise 1. It is the same for each queue type, and it counts
  handler failures.
- The delivery count is the broker header `x-delivery-count` when it is an
  integer, otherwise `null`. RabbitMQ sets it only on a quorum queue, and only
  for a delivery that ended with no ack (a `basic.reject` or a channel or
  connection close). The header name is the constant
  `WaitQueuePublisher::DELIVERY_COUNT_HEADER`.
- A retry through a wait queue removes `x-delivery-count`. The first delivery
  after the pass has no count, and the broker counts again from zero.

The delivery count drives the delivery limit of the processor (`maxDeliveries`,
5 by default, see [../core/CONSUMER.md](../core/CONSUMER.md), *Delivery
limit*).

**Use a quorum queue to get the delivery limit.** A classic queue gives no
delivery count, so the consumer has no delivery limit, and a classic queue
returns a delivery without end when a message stops the process.

## The processor never nacks a delivery of the handler

A `basic.nack` with requeue does not add to `x-delivery-count`. Thus a consumer
that nacks a failed delivery never reaches the delivery limit. The processor
never rejects or nacks a delivery as a result of a handler failure: for a
failed handler, it publishes to a wait queue and acks. The consumer returns
unacked deliveries only at a stop (see *The return rule*). It rejects the
delivery in work (this counts) and nacks only the deliveries that the handler
did not see.

## The return rule

After `basic_consume`, the consumer returns its unacked deliveries itself at
each stop that it controls. Thus the delivery count counts only the delivery
that was in work, and not the buffered deliveries of the prefetch window.

| Stop | What the consumer sends before `run()` ends | Effect on `x-delivery-count` |
| --- | --- | --- |
| Graceful stop (a signal, `stop()`, the message limit, the idle timeout): no delivery is in work | `basic.cancel`, then one `basic.nack` (tag 0, `multiple`, `requeue`) | none |
| A `Throwable` leaves the processor (a failed dead letter write, a failed or not confirmed wait-queue publish, a deserialize error that is not `MalformedMessage`, a database failure) | `basic.cancel`, then `basic.reject` of the delivery in work with `requeue`, then one `basic.nack` (tag 0, `multiple`, `requeue`); then the same `Throwable` goes on | +1 for the delivery in work only |
| The return itself fails (for example, the connection is lost) | nothing more; the original `Throwable` goes on | +1 for each unacked delivery (the broker returns them at the close) |
| A stop that the consumer does not control (a killed process, a memory fatal error, a lost connection, a broker restart) | nothing | +1 for each unacked delivery |

- `basic.cancel` comes first. Without it, the broker delivers the returned
  messages again to the same consumer before the close.
- A failure inside the return after a `Throwable` is logged at the level
  `error` with the queue name, and the original `Throwable` goes on. At a
  graceful stop, a failure of the cancel or of the nack is the error of `run()`.
- A `Throwable` that leaves the channel wait with no delivery in work (for
  example, a cancel by the server) gets the graceful return, and then goes on.
- A failure of `basic_qos` or `basic_consume` sends no return.
- The graceful return is safe when no delivery is unacked (an idle stop on an
  empty queue): the nack gives no error, and the channel stays open.

## The count after an uncontrolled stop

A stop that the consumer does not control adds 1 to the count of each unacked
delivery of the channel, also of each buffered delivery that the handler did
not see. The default prefetch of `AmqpQueueConfig` is 32, so by default one
such stop counts for up to 32 deliveries. Thus the neighbors of a bad message,
or the buffered messages of repeated crashes, can get a row with the error
`DeliveryLimitExceeded`. No data is lost: the row holds the canonical document
and is replayable.

- A prefetch of 1 makes the count exact.
- The rule for an operator: replay the rows of the class
  `DeliveryLimitExceeded` with a filter (and, after an incident, with
  `--since`), and expect the bad message to come back alone. See
  [../core/DLQ_OPERATIONS.md](../core/DLQ_OPERATIONS.md).

## Retry with TTL wait queues

Consumer-side retry uses a durable per-delay wait queue with a
`x-message-ttl` that dead-letters back to the work queue through the default
exchange (transport-native delay), rather than an in-process sleep. The queue
is named `<work queue>.wait.<delay in ms>`. The consumer declares it at the
first retry with that delay. The retry policy is in
[../core/CONSUMER.md](../core/CONSUMER.md).

The consumer puts its channel in publisher-confirm mode at the first retry. It
acks the original delivery only after the broker confirms the republish. A
nack or a confirm timeout stops the process: `run()` ends with the exception,
so `message-broker:consume` exits with a failure. The original delivery is
rejected with requeue (see *The return rule*) and is redelivered with the same
attempt.

A database failure stops the consumer in the same way, and the delivery is
redelivered (see [../core/CONSUMER.md](../core/CONSUMER.md), *Database
failures*).

## Dead-letter exchange of the work queue

**Set a dead-letter exchange on the work queue.** The consumer stops, and
RabbitMQ redelivers, when a message cannot be decoded for a temporary reason
or when the database fails. A message that always causes this, or a handler
that ends the PHP process (for example, out of memory), comes back again and
again. On a quorum queue, the delivery limit of the processor (`maxDeliveries`)
writes a dead letter for it first. The broker limit is the last guard, for
example when the dead letter write fails at each delivery: a quorum queue drops
such a message after its delivery limit (20 by default in RabbitMQ 4) when the
queue has no dead-letter exchange. A classic queue redelivers it without a
limit. Declare the work queue with `x-dead-letter-exchange` (and, for a quorum
queue, a `x-delivery-limit` that you choose), and monitor the dead-letter
queue. Keep `maxDeliveries` not higher than `x-delivery-limit`; a lower value
is recommended. When you set `x-delivery-limit` below 5, lower `maxDeliveries`
too.

The processor never rejects or nacks a delivery as a result of a handler
failure, and the consumer returns its unacked deliveries only at a stop (see
*The return rule*). Thus the broker limit is reached in two ways only:

- after stops that the consumer does not control (a killed process, a memory
  fatal error, a lost connection);
- after a dead letter write that fails at each delivery (for example, a
  database that stays down): each stop by a `Throwable` adds 1 for the delivery
  in work.

With a dead-letter exchange, the broker moves such a message to the dead letter
queue. `message-broker:dlq:import` then moves it into the `dead_letters` table
(see [DEAD_LETTERS.md](DEAD_LETTERS.md)). With no dead-letter exchange, or when
its route has no queue, the broker drops the message.

## Sequential consumers over one connection

**Running `AmqpConsumer` sequentially over one connection.** `run()` accepts
`messageLimit`/`idleTimeoutSec` — these exist for tests and batch-style
operation; production workers normally run with neither, blocking forever.
At each stop that it controls, `run()` cancels its consumer and returns its
unacked deliveries (see *The return rule*), so the next consumer on the same
channel or connection receives them. After a stop that the consumer does not
control, close the channel: the broker returns the unacked deliveries only at
the close.
