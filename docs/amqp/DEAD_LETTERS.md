# AMQP dead letters

The consumer writes its dead letters to the `dead_letters` table (see
[../core/DLQ_OPERATIONS.md](../core/DLQ_OPERATIONS.md)). RabbitMQ can also move
a message of a work queue to a dead letter queue by itself, with no handler
error: a quorum work queue moves a message above its `x-delivery-limit` through
its dead letter exchange. `AmqpDeadLetterImport` moves the messages of that
queue into the table. The topology is in [TRANSPORT.md](TRANSPORT.md#topology).

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
2. The import reads a `TransportMove` from the header `x-death`.
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

- A row keeps the headers of the message. Give read permission on the dead
  letter queue to the import user and the operators only.
- The import trusts the content of a message in the dead letter queue: a user
  with write permission on that queue can write a row with any `source` (a
  forged `x-death`). Give write permission on the dead letter queue to the dead
  letter exchange only.
- The import needs read permission (for `basic.get` and the ack) on the dead
  letter queue. It declares nothing.
