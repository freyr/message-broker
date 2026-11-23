# AMQP dead letters

The consumer writes its dead letters to the `dead_letters` table (see
[../DLQ_OPERATIONS.md](../DLQ_OPERATIONS.md)). RabbitMQ can also move
a message of a work queue to a dead letter queue by itself, with no handler
error: a quorum work queue moves a message above its `x-delivery-limit` through
its dead letter exchange. `AmqpDeadLetterImport` moves the messages of that
queue into the table. The topology is in [TRANSPORT.md](TRANSPORT.md#topology).

A consumer can also write its dead letters to the dead letter queue in place of
the table: `AmqpDeadLetterSink` (below). The import then moves those messages
into the table too, with the handler error of each.

## Queue sink

`AmqpDeadLetterSink` implements the core port `DeadLetter\DeadLetterSink` (see
[../PORTS.md](../PORTS.md)). Give it to the `DeliveryProcessor` of a
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
| `application_headers` | the headers of the dead letter, then `x-dead-letter` (the error data, see [../DLQ_OPERATIONS.md](../DLQ_OPERATIONS.md#the-header-x-dead-letter)); the `x-dead-letter` value wins on a key collision |
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

A return is not matched to its message: the sink clears its return flag before
each publish. Thus a `basic.return` of an earlier message that arrives after
the confirm timeout of its `store()` can fail a later `store()`. This failure
loses no dead letter: the processor does not ack the delivery, and the delivery
comes again. But the later `store()` already published its own dead letter,
and the broker routed and confirmed it. The delivery returns to the work queue
with one more delivery count. When a later delivery of that message succeeds,
the dead letter queue still holds a dead letter of a processed message, and the
import writes a row for it. A replay of that row keeps the message ID: it is a
duplicate that the deduplication of the consumer absorbs while its
deduplication row exists.

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
sink on that channel replaces it, so a nack of a retry becomes invisible.
Also, each `confirm_select()` sets the delivery tag counter of the php-amqplib
channel back to 1, and the broker continues its own count. When a second
publisher puts the same channel in confirm mode and publishes on it, the
counts do not agree (measured: `Server ack'ed unknown delivery_tag "2"`). Thus
each publisher with confirms needs its own channel. A second channel on the
connection of the consumer is sufficient: php-amqplib reads the frames of the
sink channel while the consumer channel waits inside its callback (the
functional round trip test shows it).

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

### Upgrade and rollback order

The import of release 0.11.0 and earlier ignores the header `x-dead-letter`.
Upgrade the process that runs `message-broker:dlq:import` to 0.11.1 or later
before a consumer uses `AmqpDeadLetterSink`.

To go back from a queue sink to a release before 0.11.1, keep this order:

1. Change each consumer back to the `dead_letters` table (the default sink),
   and deploy. No new sink message arrives.
2. Run `message-broker:dlq:import` of the current release for each dead letter
   queue that a sink wrote to, until the queue is empty. Each row keeps the
   handler error and its `replayable` mark.
3. Deploy the older release.

When the order is not kept, an import that ignores `x-dead-letter` sees a sink
message as a message with no move (it has no `x-death` entry of a move). The
row gets the dead letter queue as `source`, `MovedByTransport` as the error
class, an empty trace and `replayable` false. The error columns do not hold
the handler error. It stays only in the header `x-dead-letter` in the column
`headers` of the row (`message-broker:dlq:show` prints it). The row cannot be
replayed.

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
[../CLI_REFERENCE.md](../CLI_REFERENCE.md)) and run it from a
Kubernetes `CronJob`. It is not a long-running worker.

For each message:

1. The message becomes a `Delivery` in the same way as in the consumer, with
   the dead letter queue as the source.
2. The import reads a `TransportMove` from the header `x-death`. A message of
   the queue sink has a valid header `x-dead-letter`, which has priority: the
   row gets the dead letter of the header (case 1).
3. `DeadLetterImporter` builds the row (the cases are in
   [../DLQ_OPERATIONS.md](../DLQ_OPERATIONS.md#importing-a-dead-letter-queue-of-the-transport))
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
- In the column `headers` of the row, the `time` field of each `x-death`
  entry is the JSON form of the `\DateTime`: an object with the keys `date`,
  `timezone_type` and `timezone`. A row of the table sink and a row of the
  queue sink (after the import) hold the same form.
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
