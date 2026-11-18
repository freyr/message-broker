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

`TransactionContractTestCase` calls each factory one time in each test, before the first `begin()`, in this order: `createTransaction()`, `createDeduplicationStore()`, `createOutboxStore()`, `createDeadLetterStore()`. Thus a store can join the transaction in its constructor.
- `OutboxPublisherContractTestCase::assertDelivered()` checks that the records arrived on the transport. For example, the AMQP test reads them from a bound queue.

Put the tests that depend on the technology (SQL text, partitions, the headers of an AMQP message) in your own test classes. The contract test cases do not hold them.

## Fakes

The fakes are correct in-memory implementations of the ports. Each fake passes the same contract test case as the real implementation.

| Fake | Port |
|---|---|
| `InMemoryOutboxStore` | `OutboxStore`: it claims, and it applies the `ClaimOutcome`. It keys the rows by (`id`, `createdAt`), as the primary key of the outbox table, so a replay adds a second row with the same id. An id in the outcome applies to each claimed row with that id. `row()` and `isPublished()` use the row of the id with the latest `createdAt`; `unpublishedIds()` gives each id one time |
| `InMemoryDeduplicationStore` | `DeduplicationStore` |
| `InMemoryDeadLetterStore` | `DeadLetterStore` |
| `InMemoryTransaction` | `Transaction` |
| `FakeOutboxPublisher` | `OutboxPublisher`: it records the calls, and it can refuse ids or throw |
| `RecordingDeliveryControl` | `DeliveryControl`: it records `ack()` and `retry()` |

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

The trait `Freyr\MessageBroker\Testing\TestIsolation` gives `isolated(string $name)`. Use it for each queue, exchange or topic name of a test. Infection sets `TEST_TOKEN` for each worker, and the trait adds `_t<token>` to the name, so two workers never share a name. The PostgreSQL test set-up adds a database for each worker (see [../postgresql/STORAGE.md](../postgresql/STORAGE.md), *Test support*).
