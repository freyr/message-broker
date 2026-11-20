# Connection pooling (pgbouncer)

No component holds a session-scoped lock. The relay claim and the consumer
deduplication each run inside one transaction, and the producer joins your own
transaction. The webhook rate limiter and circuit breaker use only row locks
of one transaction or of one statement. No component uses an advisory lock, `LISTEN` or a session `SET`.

## Transaction mode: the requirement

Transaction mode (`pool_mode = transaction`) works behind pgbouncer when the
pooler supports protocol-level prepared statements: **`max_prepared_statements`
above 0**. The library is tested with **pgbouncer 1.26.1 and the value 200**.

According to the pgbouncer documentation, the setting `max_prepared_statements`
exists since pgbouncer 1.21.0, and its default is 200 since pgbouncer 1.24.0.
Versions before 1.26.1 that have the setting are not tested.

The reason: pdo_pgsql prepares statements on the server by default, and it
sends the prepare and the execute as separate round trips. This is true for
`PDO::prepare()` and also for `PDO::query()`. In transaction mode, each round
trip outside a transaction can go to a different server connection. pgbouncer
with `max_prepared_statements` above 0 tracks the prepared statements of each
client and prepares them again on the server connection that runs the execute.

## Tested configuration

The CI job `pgbouncer` (and `make test-pgbouncer` locally) runs the PostgreSQL
contract tests (`PostgreSqlOutboxStoreContractTest`,
`PostgreSqlDeduplicationStoreContractTest`, `PostgreSqlDeadLetterStoreContractTest`,
`PostgreSqlDeliveryStoreContractTest`, `PostgreSqlRateLimiterContractTest`,
`PostgreSqlCircuitBreakerContractTest`, `PostgreSqlLaneStatesContractTest`,
`PdoTransactionContractTest`), the consumer functional tests (`AmqpConsumerTest`,
`EndToEndTest`, `ParallelConsumptionTest`) and the webhook functional test
(`WebhookEndToEndTest`: the delivery loop, the dead letters and the replay)
through pgbouncer 1.26.1 with these settings:

| Setting | Value |
|---|---|
| `pool_mode` | `transaction` |
| `max_prepared_statements` | `200` |
| `server_round_robin` | `1` |
| `min_pool_size` | `2` |
| `default_pool_size` | `4` |

`server_round_robin = 1` and `min_pool_size = 2` force a different server
connection for two consecutive transactions of one client. With the defaults,
one client gets the same server connection for each transaction, and a
statement that depends on one server connection does not fail in a test. A guard test
(`PoolerRotationGuardTest`) runs first and fails when the rotation does not
happen.

One test is skipped through the pooler:
`AmqpConsumerTest::testADatabaseFailureStopsTheConsumerAndTheDeliveryIsRedelivered`.
It simulates a database failure: it terminates the server backend of the
consumer connection. A pooler gives the client a different server connection
after that, so the test cannot simulate the failure. The run on a direct
connection covers the case.

## Not supported: `max_prepared_statements = 0`

With `max_prepared_statements = 0`, or with a pooler in transaction mode that
does not track prepared statements, the library fails with:

```
SQLSTATE[26000]: Invalid sql statement name: 7 ERROR:  prepared statement "pdo_stmt_…" does not exist
```

The error also occurs for statements outside a transaction. In one test run
with this setting, the run also hung, and one pooler server connection returned
the results of other queries until pgbouncer was restarted.

## Alternative: emulated prepares

`PDO::ATTR_EMULATE_PREPARES => true` on the connection makes pdo_pgsql send no
server-side prepared statements. With `max_prepared_statements = 0` and
emulated prepares, one run of the same suites gave the same result as the
supported setting, with the same one test that does not work behind a pooler
(the database failure test above). CI does not run this variant.

## Not tested through a pooler

`message-broker:outbox:maintain` (`DETACH PARTITION ... CONCURRENTLY`) and
`message-broker:setup:schema` were tested on a direct connection only.
