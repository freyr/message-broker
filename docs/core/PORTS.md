# Ports

The core talks to storage and to the transport through ports (interfaces).
The core has no `PDO` and no `PhpAmqpLib` in its code. An adapter package
implements the ports. This table lists each port, its purpose and the classes
that implement it. A new adapter (for example MySQL or SQS) implements the same
ports and extends the contract test cases of the test kit (see
[TESTING.md](TESTING.md)).

| Port | Purpose | Implemented by |
|---|---|---|
| `Outbox\OutboxStore` | Insert outbox rows. Claim due rows of one lane and apply the outcome. Abandon the open rows of one message ID (`abandon()`). List the open rows of a lane with failed attempts (`failing()`). | `PostgreSqlOutboxStore` (PostgreSQL adapter); `InMemoryOutboxStore` (test kit) |
| `Outbox\OutboxPublisher` | Send claimed rows to the transport and report the refused rows. | `AmqpMessagePublisher` (AMQP adapter); `FakeOutboxPublisher` (test kit) |
| `Outbox\OutboxRetention` | Create the upcoming outbox partitions and drop the expired ones. | `PostgreSqlOutboxRetention` (PostgreSQL adapter) |
| `Storage\SchemaInstaller` | Create the tables, or return their DDL. | `PostgreSqlSchemaInstaller` (PostgreSQL adapter) |
| `Storage\Transaction` | Begin, commit and roll back the consumer transaction. | `PdoTransaction` (PostgreSQL adapter); `InMemoryTransaction` (test kit) |
| `Consumer\DeduplicationStore` | Record that a consumer processed a message. Prune old entries. | `PostgreSqlDeduplicationStore` (PostgreSQL adapter); `InMemoryDeduplicationStore` (test kit) |
| `DeadLetter\DeadLetterStore` | Store a dead letter (idempotent by `id`; one open row for each message ID and source). Find one. List, count, purge, get the IDs (`ids()`) and group (`stats()`) the rows that match a `DeadLetterFilter`. Mark an open row as replayed (`markReplayed()`, conditional, returns `bool`). | `PostgreSqlDeadLetterStore` (PostgreSQL adapter); `InMemoryDeadLetterStore` (test kit) |
| `Consumer\DeliveryControl` | Ack one delivery, or schedule its retry. | `AmqpDeliveryControl` (AMQP adapter); `RecordingDeliveryControl` (test kit) |
| `Consumer\MessageDispatcher` | Run the handler of an incoming message. | `CallableDispatcher` (core); your own adapter to a command bus |
| `Retry\RetryPolicy` | Decide to retry, dead-letter or discard a failed delivery. | `ExponentialRetryPolicy` and `RetryPolicyMap` (core); your own policy |
| `Serializer\WireFormat` | Encode the payload to the bytes of the `body` column. | `JsonWireFormat` (core) |
| `Serializer\Deserializer` | Decode a delivery to an `IncomingMessage`. | `JsonDeserializer` (core) |
| `Observability\BrokerEvents` | Receive the lifecycle events (see [PRODUCTION.md](PRODUCTION.md), *Observability*). | Your own class |
| `ErrorHandler` | Receive each handled failure with its context. | Your own class |
| `DeadLetter\DeadLetterSink` | Make a dead letter durable at the destination of a consumer (`store()`), the table or a queue of the transport. `DeadLetterStore` extends it. `DeliveryProcessor` takes a sink as `deadLetters`. | `PostgreSqlDeadLetterStore` (PostgreSQL adapter); `AmqpDeadLetterSink` (AMQP adapter); `InMemoryDeadLetterStore` (test kit) |
| `Webhook\SubscriptionProvider` | Give the webhook subscriptions: by message name (`forMessage()`), by lane (`find()`), and all lanes (`lanes()`). See [WEBHOOKS.md](WEBHOOKS.md). | Your own class; `InMemorySubscriptionProvider` (test kit) |
| `Webhook\DeliveryStore` | Insert delivery rows. Claim due rows of many lanes with a lease (`claim()`), write the results with the lease check and the dead letters (`complete()`), abandon old rows by date (`abandon()`), and count them (`countUnfinished()`). | `PostgreSqlDeliveryStore` (PostgreSQL adapter); `InMemoryDeliveryStore` (test kit) |
| `Webhook\DeliverySender` | Send a batch of deliveries, each with its subscription, and return one result for each (`send()`). State the longest time of one call (`maxSendMs()`). | `FakeDeliverySender` (test kit) |
| `Webhook\DeliveryRetryPolicy` | Decide between a retry at a time and a dead letter after a failed or refused result. | `DefaultDeliveryRetryPolicy` (core); your own policy |
| `Webhook\DeliveryRetention` | Create the upcoming partitions of `webhook_deliveries` and drop the expired ones that hold no unfinished row. | `PostgreSqlDeliveryRetention` (PostgreSQL adapter) |
| `Webhook\RateLimiter` | The global rate limit of the webhook requests, a token bucket for all workers: grant up to a wanted number of permits (`acquire()`), and take unused permits back up to the burst capacity (`giveBack()`). See [WEBHOOKS.md](WEBHOOKS.md#flow-control). | `NoRateLimit` (core, limits nothing); `InMemoryRateLimiter` (test kit) |
| `Webhook\CircuitBreaker` | Give the lanes that are out of circulation (`openLanes()`), and add the counts of one batch to the windows of their lanes in one write, in the order of the lane ID (`record()`, returns the lanes that left circulation and the lanes that returned). | `NoCircuitBreaker` (core, opens no lane); `InMemoryCircuitBreaker` (test kit) |
| `Webhook\LaneStates` | Give the circuit breaker state of each lane for the operator (`all()`), and put one lane back into circulation (`close()`). The implementation of `CircuitBreaker` implements it too. | `InMemoryCircuitBreaker` (test kit) |

`DeliveryControl` has no reject: the processor never rejects or nacks a
delivery as a result of a handler failure. A dead letter goes through the sink,
and a sink that throws leaves the delivery unacked.

### The contract of `DeadLetterSink`

- `store(DeadLetter $deadLetter): void` returns only when the dead letter is
  durable at the destination; else it throws. The caller acks the delivery
  only after `store()` returns.
- A call that throws can still have made the dead letter durable (for example,
  a confirm that arrives after the timeout). The caller then repeats the call;
  the table absorbs the repeat by its upsert, and the import absorbs a second
  queue message in the same way.
- `store()` is idempotent by `id`. A queue sink cannot see its earlier
  messages; the import applies the rule when it stores the message in the
  table.
- The `source`, message ID, message name, `failedAt`, attempts and
  `replayable` reach the destination as given. A sink can cut the error
  message to 1024 bytes and the error trace to 16384 bytes
  (`AmqpDeadLetterSink` does). A store that holds an open row of the same
  message ID and source keeps the `id` of that row.

### The methods of `DeadLetterStore`

- `store(DeadLetter $deadLetter): void` changes nothing when a row with the
  same `id` exists (open or replayed). Otherwise, when an open row (not
  replayed) of the same message ID and source exists, that row keeps its `id`
  and gets the message name, error class, error message, error trace,
  attempts, `failedAt`, body, headers and `replayable` of the new dead letter.
  With no open row, it adds a row with the `id` of the dead letter.
- `markReplayed(string $id): bool` is conditional: it sets `replayedAt` only
  when the row exists and `replayedAt` is null, and returns `true` only when
  this call set the mark. An unknown ID, a value that is not a valid ID (the
  PostgreSQL store sends no query for a value that is not a UUID) and a
  replayed row give `false`, with no error, and the first `replayedAt` stays.
  The PostgreSQL store runs `UPDATE ... WHERE id = :id AND replayed_at IS
  NULL` and returns `rowCount() === 1`; the row lock makes a second
  transaction wait, and under `READ COMMITTED` it then gets `false`.
- `list(DeadLetterFilter $filter, int $limit = 100, int $offset = 0)`: the
  matching rows, newest first (`failedAt` descending, then `id` descending).
- `count(DeadLetterFilter $filter)`: the count of the rows that `list()` gives
  with no limit.
- `ids(DeadLetterFilter $filter, int $limit)`: the IDs of the matching rows,
  oldest first (`failedAt` ascending, then `id` ascending), at most `$limit`.
  A `$limit` below 1 throws `InvalidArgumentException`.
- `stats(DeadLetterFilter $filter)`: one `DeadLetterStat` for each group of
  message name, source and error class (count, oldest and newest
  `failedAt`), count descending, then by name, source and error class.
- `purge(DeadLetterFilter $filter)`: deletes each matching row and returns the
  count. An empty filter deletes every row.

`DeadLetter\ReplayService` takes `(DeadLetterStore $deadLetters, OutboxStore
$outbox, Transaction $transaction, WireFormat $wireFormat, ?BrokerEvents
$events = null)`. The three ports must work on one connection: the replay
marks the row, inserts the outbox row and commits in one transaction of the
`Transaction` port (see [DLQ_OPERATIONS.md](DLQ_OPERATIONS.md) for the
two-connection limit). `replay()` throws `DeadLetterNotFound`,
`AlreadyReplayed` or `NotReplayable` for a refusal; each extends
`RuntimeException`. `check()` runs the same checks and changes nothing.

`DeadLetter\DeadLetterFilter` has nine optional criteria (`null` means no
condition), which combine with AND: `messageName`, `source`, `errorClass`,
`messageId` (equal), `failedFromMs` (inclusive), `failedUntilMs` (exclusive),
`minAttempts` (at least), `replayed` and `replayable`. The constructor throws
`InvalidArgumentException` when `minAttempts` is below 0, or when
`failedFromMs` is not lower than `failedUntilMs`. `isEmpty()` is true when no
criterion is set; `withReplayed()` returns a copy with a different `replayed`.

### The abandon methods of `OutboxStore`

- `abandon(string $id): int` marks each row with this message ID that is not
  published and not abandoned (`published_at IS NULL AND abandoned_at IS NULL`):
  it sets `abandoned_at` to now and returns the count. A replay makes a second
  row with the same ID, so the count can be more than 1. An ID that no row has
  gives 0; the PostgreSQL store also gives 0 for a value that is not a UUID,
  and sends no query. It is one statement on the connection of the store: it
  opens no transaction, and it joins a transaction that the caller opened.
- `failing(string $lane, int $minAttempts, int $limit): array` returns a list
  of `Outbox\FailingRow` (`id`, `lane`, `messageName`, `attempts`, `createdAt`,
  `availableAt`, `bodyBytes`; times in epoch milliseconds). It gives the open
  rows of the lane with `attempts >= $minAttempts`, also the rows that are not
  due yet, at most `$limit`, by `attempts` (highest first) and then by
  `createdAt` (oldest first). It never returns the body. A `$minAttempts` or a
  `$limit` below 1 throws `InvalidArgumentException`.

The claim, `failing()` and the outcome writes ignore an abandoned row.
Retention does not keep a partition for an abandoned row (see
`Outbox\OutboxRetention`: "unpublished" means `published_at IS NULL AND
abandoned_at IS NULL`).

### The methods of `DeliveryStore`

- `insert(array $records): void` writes the rows on the connection of the
  caller, so they commit or roll back with the transaction of the
  application. It opens no transaction. An empty list writes nothing.
- `claim(array $lanes, int $limitForEachLane, int $total, int $leaseMs):
  array` claims due, unfinished rows of the lanes: at most `$limitForEachLane`
  of each lane and at most `$total` in all. It sets `available_at` to now plus
  the lease and commits before it returns. Each `ClaimedDelivery` has the
  record and its lease value (the new `available_at`). Concurrent claimers
  skip the rows of each other. An empty lane list returns an empty list.
- `complete(array $outcomes): array` writes the `DeliveryCompletion` values in
  one transaction: delivered (final state), retry (attempts and
  `available_at`) and dead (the dead letter and the final state). The lease
  check applies to each outcome: a row changes only when it is unfinished and
  its `available_at` is equal to the lease value of the claim. The dead letter
  follows the open row rule of `DeadLetterStore::store()`. It returns the IDs
  of the rows that it changed.
- `abandon(int $before, ?array $lanes, int $limit): int` gives at most
  `$limit` unfinished rows with `created_at` earlier than `$before`, of the
  lanes (`null`: all lanes; `[]`: no lane), the dead letter of
  `DeliveryDeadLetter::abandoned()` (error class `abandoned`, source
  `webhook:<lane>`, open row rule) and the final state dead, in one
  transaction. A lease does not protect a row. Rows that a different session
  locks are skipped, so a call can return 0 while rows are left;
  `countUnfinished()` shows them. It returns the
  number of rows.
- `countUnfinished(int $before, ?array $lanes): array` counts, with no change,
  the rows that `abandon()` with the same arguments would take: lane => count,
  in the byte order of the lane names. PHP turns a numeric lane name into an
  `int` key; cast it back to `string`.

### The methods of the flow control ports

- `RateLimiter::acquire(int $wanted): int` returns 0 to `$wanted` whole
  permits. `giveBack(int $count): void` puts `$count` permits back; the bucket
  never holds more than the burst capacity. The rate and the burst capacity
  are the configuration of the implementation. An implementation reads the
  present time from its own source (for a database: the database time).
- `CircuitBreaker::openLanes(): list<string>` gives each lane whose
  `open_until` is later than the present time. `record(list<LaneCounts>
  $counts): LaneTransitions` takes each lane at most one time. For each lane:
  the counts of an open lane are ignored; a window older than the window
  length, and the first window after `open_until`, start again from 0; the
  trip rule of `CircuitBreakerPolicy::trips()` sets `open_until` to now plus
  the open time, starts a new window and adds 1 to the opened count.
- `LaneStates::all(): list<LaneState>` gives each lane with a result, in the
  order of the lane ID. `close(string $lane): bool` sets `openUntil` to `null`
  and starts a new window at the present time; the opened count stays. It
  returns `false` for a lane with no state.
- A database error of any of these ports propagates: the delivery loop stops.

`DeliveryRetention` has the methods of `OutboxRetention` without
`dropUnpublished`: `ensureCapacity(int $daysAhead)` and `purge(int
$retentionDays, bool $dryRun = false)`. `purge()` returns a
`RetentionReport` whose `kept` lists the expired partitions with unfinished
rows; `unpublished` and `abandoned` stay empty.
