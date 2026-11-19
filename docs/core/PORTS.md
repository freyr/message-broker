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
| `DeadLetter\DeadLetterStore` | Store a dead letter (idempotent by `id`; one open row for each message ID and source). Find one. List, count, purge, get the IDs (`ids()`) and group (`stats()`) the rows that match a `DeadLetterFilter`. Mark a row as replayed. | `PostgreSqlDeadLetterStore` (PostgreSQL adapter); `InMemoryDeadLetterStore` (test kit) |
| `Consumer\DeliveryControl` | Ack one delivery, or schedule its retry. | `AmqpDeliveryControl` (AMQP adapter); `RecordingDeliveryControl` (test kit) |
| `Consumer\MessageDispatcher` | Run the handler of an incoming message. | `CallableDispatcher` (core); your own adapter to a command bus |
| `Retry\RetryPolicy` | Decide to retry, dead-letter or discard a failed delivery. | `ExponentialRetryPolicy` and `RetryPolicyMap` (core); your own policy |
| `Serializer\WireFormat` | Encode the payload to the bytes of the `body` column. | `JsonWireFormat` (core) |
| `Serializer\Deserializer` | Decode a delivery to an `IncomingMessage`. | `JsonDeserializer` (core) |
| `Observability\BrokerEvents` | Receive the lifecycle events (see [PRODUCTION.md](PRODUCTION.md), *Observability*). | Your own class |
| `ErrorHandler` | Receive each handled failure with its context. | Your own class |

### The methods of `DeadLetterStore`

- `store(DeadLetter $deadLetter): void` changes nothing when a row with the
  same `id` exists (open or replayed). Otherwise, when an open row (not
  replayed) of the same message ID and source exists, that row keeps its `id`
  and gets the message name, error class, error message, error trace,
  attempts, `failedAt`, body, headers and `replayable` of the new dead letter.
  With no open row, it adds a row with the `id` of the dead letter.
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
