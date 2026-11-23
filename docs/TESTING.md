# Testing

The core has a test kit in the namespace `Freyr\MessageBroker\Testing\`. It is part of `src`, so it is available to every package that requires the core. The kit needs PHPUnit. Add `phpunit/phpunit` to `require-dev`.

The kit has two parts:

- Contract test cases for the authors of adapters.
- In-memory fakes for the tests of the core and of applications.

## Contract test cases

A contract test case is an abstract PHPUnit test case. It checks that an implementation keeps the contract of a port. The tests use the port only. They contain no SQL and no broker detail, so one test case serves PostgreSQL, MySQL and SQS.

| Test case | Port |
|---|---|
| `OutboxStoreContractTestCase` | `Outbox\OutboxStore` |
| `DeduplicationStoreContractTestCase` | `Consumer\DeduplicationStore` |
| `DeadLetterStoreContractTestCase` | `DeadLetter\DeadLetterStore` |
| `TransactionContractTestCase` | `Storage\Transaction` (with a deduplication store, an outbox store and a dead letter store on the same connection) |
| `OutboxPublisherContractTestCase` | `Outbox\OutboxPublisher` |
| `DeadLetterSinkContractTestCase` | `DeadLetter\DeadLetterSink` (each field of a stored dead letter, the raw bytes and the mark, two dead letters; no rule on two failures of one message) |
| `DeliveryStoreContractTestCase` | `Webhook\DeliveryStore` (insert, claim with the row limit of each lane and the total, the total cut in the order of the lane list, the lease, the lease check of `complete()`, the dead letters, the abandon of old rows and their count) |
| `RateLimiterContractTestCase` | `Webhook\RateLimiter` (`$total` caps the sum with the max-min division and its fixed vectors; a lane limit caps its lane; the global limit caps the sum; a lane with no limit; a new lane starts full; a changed rate applies at the next call; `giveBack()` to each bucket up to its burst capacity; the refill after `ageBuckets()`; a null global `Rate`; a numeric lane ID) |
| `CircuitBreakerContractTestCase` | `Webhook\CircuitBreaker` (the trip rule with the minimum and the error rate, the counts of one window add up, each lane apart, the counts of an open lane are ignored, two `record()` calls at the same time whose lanes are in opposite order, `policy()`, one `returned` after the open time, the block at the fourth trip in a row, a blocked lane stays out of circulation, a maximum of 0 never blocks) |
| `LaneStatesContractTestCase` | `Webhook\LaneStates` (the counters in the order of the lane ID, `open_until` and the new window of a trip, the first window after the return, the block fields, a recovery, `close()`, also of a blocked lane) |

To test an adapter, extend the test case and return the implementation from the abstract factory method:

```php
final class MySqlDeduplicationStoreContractTest extends DeduplicationStoreContractTestCase
{
    use MySqlTestSupport; // your own set-up: connection, schema, empty tables for each test

    protected function createStore(): DeduplicationStore
    {
        return new MySqlDeduplicationStore(self::$pdo);
    }
}
```

Each factory must return a store that is empty at the start of the test. Some test cases have optional hooks:

- `OutboxStoreContractTestCase::createSecondStore()` returns a second store with its own connection to the same rows. Without it, the tests of two claimers are skipped, and PHPUnit shows the skip message.
- `TransactionContractTestCase::createOutboxStore()` returns an outbox store on the connection of the transaction. Without it, the tests of the outbox insert are skipped.
- `TransactionContractTestCase::createDeadLetterStore()` returns a dead letter store on the connection of the transaction. Without it, the tests of the replay mark are skipped. The test of a mark and an outbox insert in one committed transaction needs both hooks.
- `OutboxPublisherContractTestCase::assertDelivered()` checks that the records arrived on the transport. For example, the AMQP test reads them from a bound queue.
- `DeliveryStoreContractTestCase` has two abstract methods: `createStore()` returns an empty delivery store, and `deadLetterStore()` returns the dead letter store that reads the dead letters of that store. The test calls `createStore()` first. The tests use the real time, because a database store reads the database time: they show the lease rules with leases of 0 ms (due again at once) and 60 s, with no sleep, and they accept a lease value up to `CLOCK_TOLERANCE_MS` (1 s) from the time of PHP. The `created_at` values are of the last minutes.
- `RateLimiterContractTestCase` has two abstract methods: `createRateLimiter()` returns a limiter with no bucket state (each bucket of a test starts full), and `ageBuckets(int $milliseconds)` moves the stored time of each bucket back by that value (an SQL `UPDATE` of the stored time, a Redis `HINCRBY`, a step of a `MockClock`). The refill is the present time minus the stored time, so the hook gives the result of a wait, and no test sleeps. The rates come from each call; the tests use rates of 0.5 to 2 permits each second, so the real time of one test refills less than one permit. An implementation with no bucket returns `false` from `hasBuckets()`: the cases of a bucket are then skipped (`NoRateLimit` does this).
- `CircuitBreakerContractTestCase::createCircuitBreaker(CircuitBreakerPolicy $policy)` returns a breaker with no lane state. The contract has no time hook. Most tests use the default window and open time, so no window and no open time ends during the test. The tests of the return and of the block use an open time of 1 ms and wait for the return with a bounded poll of `openLanes()` (at most 2 s); test the exact limits of the window and of the open time in your own test class. `recordAtTheSameTime()` runs the two `record()` calls of the test with the lanes in opposite order one after the other; an adapter with shared state overrides it to run them at the same time in two sessions, so the test shows that they do not wait for each other in a circle.
- `LaneStatesContractTestCase::createCircuitBreaker(CircuitBreakerPolicy $policy)` returns a breaker that also implements `LaneStates`, with no lane state. It accepts a time up to `CLOCK_TOLERANCE_MS` (1 s) from the time of PHP. The tests of the return, of the block and of the recovery use an open time of 1 ms and a window of 50 ms, and wait for their condition with a bounded poll of a port result (at most 2 s).
- `DeadLetterSinkContractTestCase` has two abstract methods: `createSink()` returns a sink whose destination is empty, and `storedDeadLetters()` reads the destination back (a list of `DeadLetter`, in any order). A store returns `list()`. The AMQP test reads the dead letter queue with `basic.get` and decodes each message through `DeadLetterImporter` (the header `x-dead-letter`). The tests use texts below the cuts of 1024 and 16384 bytes.

`OutboxStoreContractTestCase` has these cases for `abandon()` and `failing()`:

- An abandoned row is never claimed, also after its `available_at`.
- `abandon()` returns 1 for one open row, 0 for an id that no row has, and 0 on a repeat.
- `abandon()` does not mark a published row: publish, then abandon, gives 0.
- Two rows with the same id and a different `createdAt`: one published and one open gives 1; two open give 2.
- `failing()` returns only the open rows of the lane with `attempts >= $minAttempts`. It excludes a published row, an abandoned row, a row of a different lane and a row below the minimum. It orders by attempts, then by age, and it keeps to the limit. `bodyBytes` is `strlen()` of the body. A row leaves the list when it is abandoned.
- `failing()` rejects a `$minAttempts` or a `$limit` below 1 with `InvalidArgumentException`.

`DeadLetterStoreContractTestCase` has these cases for the open row rule, the replayable mark and the filter:

- A second `store()` of an open pair (message ID, source) keeps one row and its first `id`, with the data of the second dead letter. A `store()` after a replay of the pair adds a new open row.
- A `store()` of an `id` that is already a row (open or replayed) changes nothing.
- The same message ID on two sources gives two rows; two message IDs on one source give two rows.
- The `replayable` mark round-trips as `true` and as `false`, an upsert changes it in each direction, and `markReplayed()` keeps it.
- Each filter field alone selects the same rows in `list()`, `count()`, `ids()` and `purge()`, with the bounds: `failedFromMs` is inclusive, `failedUntilMs` is exclusive, `minAttempts` is inclusive. One case combines all fields with AND.
- `ids()` is oldest first and keeps to the limit; a limit below 1 throws. `stats()` gives one entry for each group, by count and then by name, source and error class.
- `markReplayed()` of an open row returns `true`, sets `replayedAt` to now (the tests set a `MockClock` with `Clock::set()`, so the store must read the time from `EpochMillis::now()` or `Clock::get()`) and keeps the other fields. A second call returns `false` and keeps the first `replayedAt`. An unknown ID and a value that is not a UUID return `false` with no error.

`TransactionContractTestCase` also checks that a new `markReplayed()` returns `true` after a mark rolled back.

The helper of the test case gives each dead letter a new message ID by default, so two stored dead letters are two rows.

`TransactionContractTestCase` calls each factory one time in each test, before the first `begin()`, in this order: `createTransaction()`, `createDeduplicationStore()`, `createOutboxStore()`, `createDeadLetterStore()`. Thus a store can join the transaction in its constructor.

Put the tests that depend on the technology (SQL text, partitions, the headers of an AMQP message) in your own test classes. The contract test cases do not hold them.

## Fakes

The fakes are correct in-memory implementations of the ports. Each fake passes the same contract test case as the real implementation.

| Fake | Port |
|---|---|
| `InMemoryOutboxStore` | `OutboxStore`: it claims, and it applies the `ClaimOutcome`. It keys the rows by (`id`, `createdAt`), as the primary key of the outbox table, so a replay adds a second row with the same id. An id in the outcome applies to each claimed row with that id. `row()`, `isPublished()` and `isAbandoned()` use the row of the id with the latest `createdAt`; `unpublishedIds()` gives each id one time. `abandon()` marks the open rows of an id; the claim, `failing()` and the outcome skip an abandoned row. An abandoned row is not published, so `unpublishedIds()` still lists it. A rollback restores the marks |
| `InMemoryDeduplicationStore` | `DeduplicationStore` |
| `InMemoryDeadLetterStore` | `DeadLetterStore`: `store()` keeps the rule of the port (idempotent by `id`, one open row for each message ID and source). `add()` is a seed hook that stores the object as it is, with no upsert, so a command test can seed two open rows of one pair. `callsOf()` gives the arguments of each call, with the filter object as `filter` |
| `InMemoryTransaction` | `Transaction` |
| `FakeOutboxPublisher` | `OutboxPublisher`: it records the calls, and it can refuse ids or throw |
| `RecordingDeliveryControl` | `DeliveryControl`: it records `ack()` and `retry()` |
| `InMemoryDeliveryStore` | `Webhook\DeliveryStore`: the claim with a lease and a row limit for each lane (the lanes in the order of the keys, the rows of a lane by `available_at`), the due counts, the lease check of `complete()`, and the abandon of old rows and their count. The dead letters of `complete()` and `abandon()` go to an `InMemoryDeadLetterStore` (a constructor argument, else a new one; `deadLetters()` gives it) through `store()`. `row()`, `outcome()` (`Delivered`, `Dead` or `null` while unfinished) and `records()` show the rows; `$claims`, `$claimCalls` and `$dueCountCalls` record the calls, and `$onClaim` runs at the start of each claim |
| `FakeDeliverySender` | `Webhook\DeliverySender`: it records the calls (`$calls`, `sentIds()`) and returns the result of `$respond` for each pair (default: delivered). `$onSend` runs at the start of each call; `maxSendMs()` is a constructor argument (default 1000) |
| `InMemorySubscriptionProvider` | `Webhook\SubscriptionProvider`: `add()` registers a subscription with its message names, `remove()` takes it away. `$lanesCalls` counts the reads of `lanes()`, and `$finds` records the `find()` calls |
| `InMemoryRateLimiter` | `Webhook\RateLimiter`: a global bucket (none with a null global `Rate`) and a bucket for each lane with a limit, with the rates of each call. A new bucket starts full. The refill and the division are those of `PermitDivision`, with the time of `symfony/clock` (a `MockClock` step ages the buckets). `$acquires` (wanted, total and granted) and `$givenBack` record the calls, and `$onAcquire` runs at the start of each `acquire()` with the call number |
| `InMemoryCircuitBreaker` | `Webhook\CircuitBreaker` and `Webhook\LaneStates`, with the rules of the ports (also the recovery and the block) and the values of a `CircuitBreakerPolicy` (a constructor argument, else the defaults; `policy()` returns it). The order of the lane IDs is the byte order of the strings. `$records` records the counts of each `record()`, `$openLanesCalls` counts the reads of `openLanes()`, and `$onRecord` runs at the start of each `record()` with the call number |

An application test uses a fake in place of a real store:

```php
$transaction = new InMemoryTransaction();
$deduplication = new InMemoryDeduplicationStore($transaction);

$transaction->begin();
$deduplication->acquire($message, 'orders_consumer'); // true
$transaction->rollBack();

$deduplication->acquire($message, 'orders_consumer'); // true again: the entry rolled back
```

A store that you pass the `InMemoryTransaction` joins it. A rollback restores each joined store to its state at `begin()`. The fakes have no isolation between transactions and no nesting.

The fakes read time from `symfony/clock`. Use `Clock::set(new MockClock(...))` to control it.

## Parallel test workers

The trait `Freyr\MessageBroker\Testing\TestIsolation` gives `isolated(string $name)`. Use it for each queue, exchange or topic name of a test. Infection sets `TEST_TOKEN` for each worker, and the trait adds `_t<token>` to the name, so two workers never share a name. The PostgreSQL test set-up adds a database for each worker (see [../storage/postgresql/docs/STORAGE.md](../storage/postgresql/docs/STORAGE.md), *Test support*).
