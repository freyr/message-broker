# AMQP dead letters

The consumer writes its dead letters to the `dead_letters` table (see
[../core/DLQ_OPERATIONS.md](../core/DLQ_OPERATIONS.md)). RabbitMQ can also move
a message of a work queue to a dead letter queue by itself, with no handler
error: a quorum work queue moves a message above its `x-delivery-limit` through
its dead letter exchange. `AmqpDeadLetterImport` moves the messages of that
queue into the table. The topology is in [TRANSPORT.md](TRANSPORT.md#topology).

A consumer can also write its dead letters to the dead letter queue in place of
the table: `AmqpDeadLetterSink` (below). The import then moves those messages
into the table too, with the handler error of each.

## Queue sink

`AmqpDeadLetterSink` implements the core port `DeadLetter\DeadLetterSink` (see
[../core/PORTS.md](../core/PORTS.md)). Give it to the `DeliveryProcessor` of a
consumer as `deadLetters`, in place of the table store:

```php
use Freyr\MessageBroker\Adapter\Amqp\AmqpDeadLetterConfig;
use Freyr\MessageBroker\Adapter\Amqp\AmqpDeadLetterSink;

$deadLetterChannel = $connection->channel(); // its own channel: see below

$processor = new DeliveryProcessor(
    deserializer: $deserializer,
    dispatcher: $dispatcher,
    transaction: $transaction,
    deduplication: $deduplication,
    retryPolicy: $retryPolicy,
    deadLetters: new AmqpDeadLetterSink($deadLetterChannel, new AmqpDeadLetterConfig('dlx', 'orders_q.dlq')),
    name: 'orders_consumer',
);
```

`new AmqpDeadLetterConfig(string $exchange, string $routingKey, int
$confirmTimeoutSec = 5)`:

- `$exchange` can be `''` (the default exchange). Then `$routingKey` is the
  name of the dead letter queue, and it must not be empty.
- `$confirmTimeoutSec` must be 1 or more.
- A value that breaks a rule throws `InvalidArgumentException`.

The table stays the default sink. Each consumer has one sink.

### The message of the sink

| Part | Value |
| --- | --- |
| exchange, routing key | `AmqpDeadLetterConfig::$exchange`, `AmqpDeadLetterConfig::$routingKey` |
| body | the body of the dead letter: the canonical document, or the raw bytes of a malformed message |
| `application_headers` | the headers of the dead letter, then `x-dead-letter` (the error data, see [../core/DLQ_OPERATIONS.md](../core/DLQ_OPERATIONS.md#the-header-x-dead-letter)); the `x-dead-letter` value wins on a key collision |
| `message_id` property | the message ID of the dead letter |
| `delivery_mode` | persistent |
| `mandatory` flag | set |

`store()` publishes the message and waits for the publisher confirm
(`wait_for_pending_acks_returns()`). It returns only after the broker acked the
message and returned nothing. A nack or a return throws `RuntimeException`; a
confirm that does not arrive in time throws the `AMQPTimeoutException` of
php-amqplib. Then the processor does not ack the delivery: the consumer stops,
and the delivery returns to the work queue. A nack that arrives late, for an
earlier message whose confirm timed out, does not fail a later `store()`.

The error message and the error trace in `x-dead-letter` are cut to 1024 and
16384 bytes, because all headers of an AMQP message must fit one frame (131064
bytes). A larger header closes the connection. The row that the import writes
holds the cut text.

The sink declares no exchange and no queue.

### The own channel

Give the sink a channel that no other publisher uses. At its first `store()`,
the sink puts the channel in confirm mode and sets the nack handler and the
return listener of the channel. A channel has one of each:
`WaitQueuePublisher` keeps its own nack handler on the consumer channel, and a
sink on that channel replaces it, so a nack of a retry becomes invisible. A
second channel on the connection of the consumer is sufficient: php-amqplib
reads the frames of the sink channel while the consumer channel waits inside
its callback (the functional round trip test shows it).

### The mandatory flag

Without the mandatory flag, the broker drops a message that no binding routes
and still confirms it: the consumer would ack a delivery whose dead letter is
lost. With the flag, the broker sends `basic.return` and then the ack, and
`store()` throws. The failure repeats at each delivery, until the delivery limit
of the broker moves the message through the dead letter exchange of the work
queue. When the sink uses that same exchange and routing key, a missing binding
thus loses the message.

### Setup test before the first deployment

Before the first deployment of a queue sink, prove the binding: publish a test
message through the configured exchange and routing key, with the mandatory
flag, and confirm that it arrives in the dead letter queue. For example:

```php
$channel = $connection->channel();
$channel->confirm_select();
$returned = false;
$channel->set_return_listener(static function () use (&$returned): void {
    $returned = true;
});
$channel->basic_publish(new AMQPMessage('setup test'), 'dlx', 'orders_q.dlq', true);
$channel->wait_for_pending_acks_returns(5);
// $returned must be false, and the message must be in orders_q.dlq. Remove it
// before the first import: the import would store it as a row of the queue.
```

## Import

```php
use Freyr\MessageBroker\Adapter\Amqp\AmqpDeadLetterImport;
use Freyr\MessageBroker\DeadLetter\DeadLetterBuilder;
use Freyr\MessageBroker\DeadLetter\DeadLetterImporter;

$importer = new DeadLetterImporter($deadLetterStore, $deserializer, new DeadLetterBuilder());
$import = new AmqpDeadLetterImport($channel, 'orders_q.dlq', $importer);

$imported = $import->run(limit: 1000); // null: until the queue is empty
```

`run(?int $limit = null): int` reads the queue with `basic.get` until the queue
is empty or the limit is reached, and returns the number of imported messages.
Register it with `message-broker:dlq:import` (see
[../core/CLI_REFERENCE.md](../core/CLI_REFERENCE.md)) and run it from a
scheduler. It is not a daemon.

For each message:

1. The message becomes a `Delivery` in the same way as in the consumer, with
   the dead letter queue as the source.
2. The import reads a `TransportMove` from the header `x-death`. A message of
   the queue sink has a valid header `x-dead-letter`, which has priority: the
   row gets the dead letter of the header (case 1).
3. `DeadLetterImporter` builds the row (the cases are in
   [../core/DLQ_OPERATIONS.md](../core/DLQ_OPERATIONS.md#importing-a-dead-letter-queue-of-the-transport))
   and stores it.
4. Only after the store returns, the import sends `basic.ack`.

When the store throws, the import returns the message with `basic.nack`
(`requeue`), and `run()` throws the same error. On a quorum dead letter queue,
this nack adds no delivery count (measured with `basic.get` on RabbitMQ 4).
When the nack fails too (the connection is lost), the store error goes on, and
the close of the channel returns the message. Two runs on one queue at the same
time are safe: each `basic.get` gives a different message, and the store keeps
one open row for each message and source.

### The read of `x-death`

RabbitMQ adds one `x-death` entry for each queue and reason that moved the
message, the newest entry first. The import takes the newest entry whose
`reason` is not `expired`:

- `sourceQueue` is the `queue` field, `reason` is the `reason` field, and
  `count` is the `count` field (the number of moves from that queue for that
  reason).
- The `time` field is an AMQP timestamp with second precision; php-amqplib
  decodes it to a `\DateTime`. It becomes epoch milliseconds (seconds × 1000).
  With no time, the row gets the time of the import.
- An entry with no string `queue`, no string `reason` or no integer `count` is
  skipped.

The `expired` entries come from the retry wait queues: their messages return to
the work queue, so they are no move to a dead letter queue. Thus a message that
passed a wait queue and then reached the delivery limit gets the work queue as
`source`, not the wait queue. With no entry that matches, the import has no
move, and the row gets the dead letter queue as `source` and is not
replayable.

## Permissions of the dead letter queue

- A row keeps the headers of the message. A message of the queue sink also
  carries the error message and the stack trace in `x-dead-letter`, and a
  stack trace can hold file paths and argument values. Give read permission on
  the dead letter queue to the import user and the operators only.
- The import trusts the content of a message in the dead letter queue: a user
  with write permission on that queue can write a row with any `source` and
  error data (a forged `x-death` or `x-dead-letter`). Give write permission
  on the dead letter queue to the dead letter exchange and the consumers with
  a queue sink only.
- The import needs read permission (for `basic.get` and the ack) on the dead
  letter queue. The queue sink needs write permission on its exchange. Neither
  declares anything.
