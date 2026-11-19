# Delivery processor and retry

`DeliveryProcessor` holds the transport-neutral exactly-once algorithm for one
delivery. It imports no transport class. A transport loop gives it a `Delivery`
and a `DeliveryControl`. The AMQP loop is in
[../amqp/CONSUMER.md](../amqp/CONSUMER.md). The PostgreSQL stores that the
processor uses are in [../postgresql/STORAGE.md](../postgresql/STORAGE.md).

## The algorithm

For each delivery, the processor first checks the delivery limit, then runs the
normal flow. The delivery limit (see *Delivery limit* below): when the delivery
count of the transport is at or above `maxDeliveries`, the processor writes a
dead letter with the error `Consumer\DeliveryLimitExceeded` and acks. It does
not begin a transaction, does not touch the deduplication store and does not
call the handler.

The normal flow has these steps:

1. It deserializes the body. A `MalformedMessage` never improves, so the
   processor writes a dead letter at once and acks. Any other failure (for
   example, a dependency that is not available yet) propagates. The delivery
   stays unacked, is redelivered, and the process restarts.
2. It begins a transaction. When `BEGIN` fails, the error propagates, the
   process stops, and no retry attempt is used.
3. It acquires the deduplication entry of the message for the consumer `name`.
   For a duplicate, it commits, acks and skips the handler.
4. It calls the dispatcher inside the same transaction, commits, and acks.
5. When the dispatcher throws, it rolls back. When the rollback fails (a dead
   connection), the error propagates and the delivery is redelivered. Otherwise
   the retry policy decides: `Retry` (a delayed, confirmed redelivery, then
   ack), `DeadLetter` (a row in `dead_letters`, then ack) or `Discard` (ack).

Every path that returns normally has acked the delivery. A dead letter of a
malformed message keeps the raw bytes and is not replayable. A dead letter of
a failed handler keeps the decoded message and is replayable (see
[DLQ_OPERATIONS.md](DLQ_OPERATIONS.md)).

## The fields of a dead letter

A raw dead letter (a malformed message, or a message at the delivery limit that
the deserializer cannot read) gets its message ID from the first source that
exists:

1. The `x-message-id` header of the envelope.
2. The message ID of the transport (`Delivery::$fallbackMessageId`).
3. A generated ID: `unknown-` and a UUID v7, for example
   `unknown-019a8f2e-6b1c-7d3a-9e4f-2a6b8c0d1e3f`. Each dead letter gets a new
   value, so two messages with no ID never share one row. The `DEAD_LETTERED`
   event, the log and the `ErrorHandler` context give the same value.

The log of a transient deserialize failure writes no row, so its `message_id`
stays `unknown` when the delivery has no ID.

A raw dead letter has `replayable` `false`: its body is the raw wire bytes. A
dead letter of a failed handler, and a dead letter at the delivery limit that
the deserializer can read, has `replayable` `true`: its body is the canonical
document.

The store keeps one open row for each message ID and work queue. When the
process stops after the dead letter write and before the ack, the delivery
comes back and fails again: the second write updates the open row with the
data of the second failure, so the table holds one row (see
[DLQ_OPERATIONS.md](DLQ_OPERATIONS.md)). A raw dead letter with a generated ID
gets a second row in this case, because its second ID is a new value.

Each `DeadLetter` holds text that each store can keep. The constructor of
`DeadLetter\DeadLetter` (and thus `DeadLetter::fromFailure()`) applies three
rules:

- `source`, `messageId`, `messageName`, `errorClass`, `errorMessage`,
  `errorTrace`, each header key and each string header value (nested too)
  become valid UTF-8. Each invalid byte sequence becomes U+FFFD.
- A NUL character in the same fields becomes U+FFFD. PostgreSQL refuses a NUL
  in a text column and in JSONB.
- `source`, `messageId`, `messageName` and `errorClass` are cut to 255
  characters (the size of their `VARCHAR(255)` columns).

The body stays the exact bytes. A value that obeys the rules does not change.
Thus a header with invalid UTF-8, a NUL character or a name above 255
characters no longer makes the dead letter write fail at each delivery. The
cost: the invalid bytes of a binary header value are not kept exactly.

## Delivery limit

A message that stops the process (for example, the handler runs out of memory,
or the database fails at each try) comes back again and again. The delivery
limit stops this loop with a dead letter. The last constructor parameter of
`DeliveryProcessor` sets it:

```php
$processor = new DeliveryProcessor(
    // ... the other arguments, see ../QUICKSTART.md
    maxDeliveries: 5, // the default; null turns the limit off
);
```

- `Delivery::$deliveryCount` is the number of earlier deliveries of the message
  that ended with no ack. The transport gives it. The first delivery has no
  count (`null`), then the count is 1, 2, 3.
- When `maxDeliveries` is not `null`, the count is not `null`, and the count is
  at or above `maxDeliveries`, the processor writes a dead letter and acks. With
  the default 5, the sixth delivery after five process stops becomes a dead
  letter.
- The processor deserializes the message only to store the canonical document,
  as for a failed handler. When the deserializer throws, it stores the raw
  delivery.
- The error class of the row is `Consumer\DeliveryLimitExceeded`, and its
  message names the count and the limit. `attempts` is the attempt of the
  delivery (`Delivery::$attempt`), never the delivery count.
- The processor records `DEAD_LETTERED`, logs a warning (`Consumer delivery
  limit reached; dead-lettered`) and calls the `ErrorHandler`, with the context
  keys `queue`, `message_id`, `delivery_count`, `max_deliveries`, `attempt`.
- When the dead letter write fails, the error propagates and the delivery stays
  unacked.
- A value below 1 throws `InvalidArgumentException` at construction.
- A transport that gives no count (`null`) has no delivery limit. For AMQP, only
  a quorum queue gives a count (see [../amqp/CONSUMER.md](../amqp/CONSUMER.md)).

### The rule for the two limits

The consumer has two independent limits:

| Limit | Counts | Source (AMQP) | Starts again |
| --- | --- | --- | --- |
| Attempt limit (`maxAttempts` of the retry policy) | Handler failures | `x-attempt` | Never; a replay is a new message. |
| Delivery limit (`maxDeliveries` of the processor) | Deliveries that ended with no ack (a process stop) | `x-delivery-count` | After each pass through a wait queue. |

A message can fail its handler two times and then stop the process five
times. Its dead letter then has `attempts = 3` and the error
`DeliveryLimitExceeded`. Keep `maxDeliveries` not higher than the delivery
limit of the broker (for RabbitMQ, `x-delivery-limit` of a quorum queue, 20 by
default). A lower value is recommended.

## Retry policy

The retry delay is applied by the transport. For AMQP, it is a TTL wait queue
(see [../amqp/CONSUMER.md](../amqp/CONSUMER.md)).

```php
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Retry\ExponentialRetryPolicy;

$retryPolicy = new ExponentialRetryPolicy(
    maxAttempts: 5,
    backoff: Backoff::exponential(initialDelayMs: 1_000, maxDelayMs: 300_000),
);
```

`ExponentialRetryPolicy::decide()` returns `retry` (republish to the TTL wait
queue, `x-attempt` incremented) while `$attempt < maxAttempts`, otherwise
`deadLetter`. Any class that implements `Freyr\MessageBroker\Retry\RetryPolicy`
(`decide(int $attempt, Throwable $error, IncomingMessage $message)`) replaces
it; the message parameter lets a policy decide per message name.

`$attempt` is the number of the attempt that failed, starting at 1. The
delay is `Backoff::delayForAttempt($attempt)`. The default `backoff` and the
`Backoff::exponential()` multiplier (`4.0`) are the same as for the relay (see
[RELAY.md](RELAY.md), *Backoff*). With `maxAttempts: 5` and the defaults, the
failures of attempts 1 to 4 retry after 1 s, 4 s, 16 s and 64 s. The failure of
attempt 5 goes to the dead letters.

### Error class lists

`ExponentialRetryPolicy` has two optional lists of throwable classes or
interfaces. Each entry must be a class or interface that is a `Throwable`;
the constructor throws `InvalidArgumentException` for any other entry.

```php
$retryPolicy = new ExponentialRetryPolicy(
    maxAttempts: 5,
    deadLetterOn: [DomainRuleViolated::class],
    discardOn: [StaleEvent::class],
);
```

- `deadLetterOn`: an error that matches the list dead-letters at once, with no retry.
- `discardOn`: an error that matches the list is acked and dropped. No dead letter is written.

The match is `instanceof`, so a subclass of a listed class and a class that
implements a listed interface match. `decide()` checks in this order:

1. The error matches `deadLetterOn`: `deadLetter`.
2. The error matches `discardOn`: `discard`.
3. `$attempt >= maxAttempts`: `deadLetter`.
4. Otherwise: `retry` with the backoff delay.

An error that is in both lists is dead-lettered, so an ambiguous
configuration never loses data. A `discardOn` error at the last attempt is
discarded, not dead-lettered. Both lists are empty by default.

### A policy for each message name

`Retry\RetryPolicyMap` selects a policy by the message name. The match is
exact (no pattern, no wildcard). A name with no entry uses the default policy.

```php
use Freyr\MessageBroker\Retry\RetryPolicyMap;

$retryPolicy = new RetryPolicyMap(
    default: new ExponentialRetryPolicy(),
    policies: [
        'order.placed' => new ExponentialRetryPolicy(maxAttempts: 10),
    ],
);
```

The constructor throws `InvalidArgumentException` for an empty name or a
value that is not a `RetryPolicy`.

### Events

`DeliveryProcessor` records two events for the retry policy (see
[PRODUCTION.md](PRODUCTION.md), *Observability*):

- `BrokerEvents::RETRIED` (`message.retried`): after the transport confirmed
  the retry. Context keys: `consumer`, `source`, `message_id`,
  `message_name`, `attempt`, `next_attempt`, `delay_ms`, `error_class`. A
  refused retry throws and records no event.
- `BrokerEvents::DISCARDED` (`message.discarded`): for a `Discard` decision.
  Context keys: `consumer`, `source`, `message_id`, `message_name`,
  `attempt`, `error_class`.

A dead letter records `DEAD_LETTERED`, as before.

## Database failures

A database failure is not a handler failure. When `BEGIN` fails, or the
rollback fails on a dead connection, the consumer reports the error and stops.
The delivery is redelivered, and no retry attempt is used.

**Do not catch a database error in a handler and continue.** The handler runs
inside the consumer transaction. In PostgreSQL, one failed statement aborts
the whole transaction. A `COMMIT` of an aborted transaction does a `ROLLBACK`
and returns no error, so `PDO::commit()` returns `true`. When the handler
catches the exception and returns, the consumer commits and acks the message,
but the handler work and the dedup row are not in the database. The message
is lost, and nothing is logged. The consumer does not detect this. Let
database errors propagate; the consumer then rolls back and uses the retry
path. For a statement that is allowed to fail, use `INSERT ... ON CONFLICT`,
or run it in a savepoint (for example a nested Doctrine DBAL transaction).
