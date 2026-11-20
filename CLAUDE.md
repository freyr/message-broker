# Freyr Message Broker

Standalone PHP messaging library (PHP 8.4 and 8.5, composer constraint ≥ 8.4): transactional outbox (partitioned PostgreSQL table, no ordering guarantee), consumer-side deduplication and DLQ, an AMQP producer/consumer. No framework coupling, plain PDO on PostgreSQL.

**Core concept.** The aim is a *true* outbox/inbox **exactly-once** layer for the PHP community — at-least-once delivery + consumer-side dedup/DLQ yielding exactly-once *processing*, the semantics that normally demand bespoke per-project glue. This library ships the **AMQP transport only** (RabbitMQ) so teams adopt it within their own infrastructure rather than being forced onto one broker: non-FIFO competing-consumer queues, routed by message name. There is no ordering at all: one `Relay` class (core, behind the `OutboxPublisher` port; the AMQP transport is `AmqpMessagePublisher`), and any number of identical workers drain one lane via `FOR UPDATE SKIP LOCKED` claims, so rows publish in any order and consumers must be order-insensitive. Consumer-side dedup absorbs the duplicates a worker crash can produce. The relay marks rows published (`published_at`) and never deletes; `message-broker:outbox:maintain` drops expired daily partitions with `DETACH PARTITION ... CONCURRENTLY` (PostgreSQL 14+, so there is no DEFAULT partition and partitions are created ahead; pg_partman may create them). An expired partition that still holds unpublished rows is kept by default (reported, and the command exits with FAILURE); `--drop-unpublished` drops it with those rows (the retention period then is a hard age limit). Scope is **PostgreSQL + AMQP only**: there is no ordering of any kind (no per-lane order, no per-key FIFO, no Kafka transport). Research + decisions live in the vault.

Messages are JSON (`JsonWireFormat`, `JsonDeserializer`). Encoding happens at **produce time** (the outbox `body` BYTEA column holds the final wire bytes, payload only; produce-time headers live in a `headers` JSONB column); the PHP relay is a pure byte-pump that builds the `x-message-id`, `x-message-name` and `x-created-at` transport headers from the `id`, `message_name` and `created_at` columns. A payload that cannot be encoded fails at produce. There is no Avro and no schema registry.

Design spec and research notes live in the vault: `~/Vaults/Freyr/message-broker/`.

## Layout

Four folders, four PSR-4 roots (the package is one Composer package for now; a repo split is planned):

- `src/broker/` is the core, namespace `Freyr\MessageBroker\`. Ports, relay loop, delivery processor, console commands, wire format, retry, and the webhook delivery queue in `src/broker/Webhook/` (ports `SubscriptionProvider`, `DeliveryStore`, `DeliverySender`, `DeliveryRetryPolicy`, `DeliveryRetention`, and the flow control ports `RateLimiter`, `CircuitBreaker`, `LaneStates` with the values `Rate`, `RateLimits`, `CircuitBreakerPolicy` and the shared rules `PermitDivision`; `WebhookProducer`, `DeliveryLoop`; see `docs/core/WEBHOOKS.md`). No `PDO`, no `PhpAmqpLib`, no adapter class in its code, and no `{@see}` to an adapter class in its docblocks.
- `src/adapter/postgresql/` is the PostgreSQL adapter, flat namespace `Freyr\MessageBroker\Adapter\PostgreSql\` (stores, retention, schema installer, `PdoTransaction`).
- `src/adapter/amqp/` is the AMQP adapter, flat namespace `Freyr\MessageBroker\Adapter\Amqp\` (consumer, publisher, wait queue, configs).
- `src/adapter/http/` is the HTTP adapter, flat namespace `Freyr\MessageBroker\Adapter\Http\` (`HttpDeliverySender` implements `Webhook\DeliverySender` with Symfony HttpClient; `StandardWebhooksSigner`, `ResponseClassifier`, `HttpSenderConfig`).

An adapter imports the core and its own package only, never another adapter. Deptrac enforces these rules (`deptrac.yaml`): the core uses no adapter, no `PDO` and no `PhpAmqpLib`; the PostgreSQL adapter uses no `PhpAmqpLib`; the AMQP adapter uses no `PDO`; only the HTTP adapter uses `Symfony\Component\HttpClient`, `Symfony\Contracts\HttpClient` and `Symfony\Component\HttpFoundation`. Tests follow the same split: `tests/broker/` (no database, no broker), `tests/adapter/postgresql/` (needs PostgreSQL), `tests/adapter/amqp/` (needs RabbitMQ and PostgreSQL) and `tests/adapter/http/` (unit tests with `MockHttpClient`; functional tests against the webhook test server `tools/webhook-test-server/server.mjs`, and an end-to-end test that also needs PostgreSQL), run as the PHPUnit suites `broker`, `postgresql`, `amqp` and `http`. A test under `tests/adapter/` never imports the test namespace of the core (`Freyr\MessageBroker\Tests\`) or of another adapter; what adapter tests share comes from the test kit (below). The upgrade table from 0.6.x is in `UPGRADE.md`. The documents follow the same split: `docs/core/` (relay, delivery processor, ports, CLI, DLQ, production, test kit), `docs/postgresql/` (tables, retention, pooling, webhook storage), `docs/amqp/` (transport, consumer) and `docs/http/` (HTTP sender, signature, network rules), plus `docs/QUICKSTART.md` (uses all three; it belongs to the core). A document links to a document of another package and never repeats it. The README of each future package is in `packages/<name>/README.md`.

### Test kit

`src/broker/Testing/` (namespace `Freyr\MessageBroker\Testing\`) is in `src`, not in `tests`, because an adapter repo gets the `src` of the core and not its `tests`. It holds, and may use PHPUnit (so `phpunit/phpunit` is in `suggest`):

- The abstract contract test cases `OutboxStoreContractTestCase`, `DeduplicationStoreContractTestCase`, `DeadLetterStoreContractTestCase`, `TransactionContractTestCase`, `OutboxPublisherContractTestCase`, `DeliveryStoreContractTestCase`, and for the webhook flow control `RateLimiterContractTestCase` (with the hook `ageBuckets()`: no test sleeps for the refill), `CircuitBreakerContractTestCase` and `LaneStatesContractTestCase` (no time hook: the cases of the return, the block and the recovery wait with a bounded poll of at most 2 s). An adapter test extends one, returns its implementation from the abstract factory method, and inherits the tests. The tests state the contract of the port and never a detail of PostgreSQL or RabbitMQ. A new adapter (MySQL, SQS) must extend them.
- The in-memory fakes `InMemoryOutboxStore`, `InMemoryDeduplicationStore`, `InMemoryDeadLetterStore`, `InMemoryTransaction` (the in-memory stores join it and roll back with it), `FakeOutboxPublisher`, `RecordingDeliveryControl`, and for the webhook queue `InMemoryDeliveryStore` (dead letters go to an `InMemoryDeadLetterStore`), `FakeDeliverySender`, `InMemorySubscriptionProvider`, `InMemoryRateLimiter` and `InMemoryCircuitBreaker` (also a `LaneStates`). Each fake passes the same contract test case as the real implementation (`tests/broker/Testing/`).
- Small helpers: `OrderPlaced`, `Unserializable`, `Recording*`, `StubDeduplicationStore` and the trait `TestIsolation` (`isolated()` names for parallel workers).

`src/adapter/postgresql/Testing/PostgreSqlTestSupport` is the PostgreSQL part (connection, schema, `TEST_TOKEN` database); the AMQP adapter tests use it too. Test code in `Testing/` is not mutated (Infection) and not counted as coverage. See `docs/core/TESTING.md`.

## Deployment

Kubernetes only. Each worker (relay, consumer, webhook delivery loop) is the only process of its container and runs in a `Deployment`; Kubernetes starts the container again after each exit, exit code 0 included, and HPA or KEDA changes the number of pods. The commands that end after one run (`outbox:maintain`, `webhook:maintain`, `dedup:cleanup`, `dlq:import`) run in a `CronJob`. The library has no fork, no worker pool and no process supervisor, and the documents give no instructions for systemd or Supervisor. See `docs/core/PRODUCTION.md`.

## Connection pooling

No component holds a session-scoped lock. The relay and the consumer use only transaction-scoped locks. Transaction mode works behind pgbouncer when the pooler supports protocol-level prepared statements: `max_prepared_statements` above 0. Tested in CI (job `pgbouncer`, and `make test-pgbouncer`) with pgbouncer 1.26.1, the value 200 and forced rotation of server connections; the guard test `PoolerRotationGuardTest` proves the rotation. The setting exists since pgbouncer 1.21.0 (pgbouncer documentation); versions before 1.26.1 are not tested. pdo_pgsql sends the prepare and the execute as separate round trips, so with `max_prepared_statements = 0` the library fails with `prepared statement "pdo_stmt_…" does not exist`, also outside a transaction: not supported. With the value 0 and `PDO::ATTR_EMULATE_PREPARES => true`, one run of the same suites gave the same result as the supported setting, with the same one test that does not work behind a pooler; CI does not run this variant. `outbox:maintain` and `setup:schema` were tested on a direct connection only. See `docs/postgresql/CONNECTION_POOLING.md`.

## After every code change

Run quality gates and tests through Docker (never host PHP):

```
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm php vendor/bin/phpstan analyse --memory-limit=-1
docker compose run --rm php vendor/bin/ecs check
docker compose run --rm php vendor/bin/deptrac analyse
```

Functional tests need PostgreSQL, RabbitMQ and the webhook test server — all three in compose. The test server is `tools/webhook-test-server/server.mjs` (Node.js, built-in modules only): the compose service `webhook-server` (started by `depends_on` of `php`), and the http tests find it through `WEBHOOK_SERVER_URL` (default `http://webhook-server:8080`). The CI workflow starts no compose: each job that runs the suite `http` (functional tests, mutation tests, split rehearsal, PHP 8.6 advisory) starts the server as a background process and sets `WEBHOOK_SERVER_URL=http://localhost:8080`; a new such job needs the same step. Each test uses its own path prefix of the server's request log (`GET /_requests?prefix=`), so parallel workers can share one server.

The image builds on PHP 8.4 by default. To run on another version, rebuild with the build argument: `PHP_VERSION=8.5 docker compose build php`. CI tests 8.4 and 8.5 and runs PHP 8.6 as an advisory job.

## Mutation testing

Infection measures the quality of the tests; pcov (in the Docker image) collects the coverage. `make infection` runs the full suite on all CPU cores (about 1 minute locally); reports go to `var/infection/` (`infection.html` lists each escaped mutant). The thresholds are in `infection.json5` (`minMsi`, `minCoveredMsi`): 94% (measured 95.7%). Raise them when the tests improve; never lower them.

CI runs Infection on the changed lines of `src/broker/` and `src/adapter/` on each pull request (part of `ci-gate`), and the full suite on `main`. A pull request whose changed lines score below the threshold fails: add or tighten the tests that the escaped mutants show.

Parallel workers are isolated. Infection sets `TEST_TOKEN` for each worker; the functional base classes (`FunctionalTestCase`, `AmqpFunctionalTestCase`, both use `Adapter\PostgreSql\Testing\PostgreSqlTestSupport`) then use the database `<name>_t<token>` (created on first use) and appends `_t<token>` to queue and exchange names. Pass every AMQP queue or exchange name of a functional test through `self::isolated()`.

## Package manifests and split rehearsal

`packages/<name>/` (`core`, `postgresql`, `amqp`, `http`) holds the files that will be at the ROOT of each future repo: `composer.json`, `phpunit.xml.dist` and `infection.json5`. The code and the tests stay where they are; in the future repo they are `src/` and `tests/`. The root `composer.json` stays the one package that users install, with `replace` for the three adapter packages and no `branch-alias`.

`make split-rehearsal PACKAGE=<core|postgresql|amqp|http> [ARGS=--infection]` (script `tools/split-rehearsal.sh`, runs in the `php` service) assembles the four future packages in a temporary folder, installs the chosen one alone through Composer path repositories (no package of this repo comes from Packagist) and runs its tests alone. It fails when a package needs a class of another package that its manifest does not give. CI runs the job `split-rehearsal` for each package (part of `ci-gate`), with `--infection` on `main`.

At each minor release, change `REHEARSAL_VERSION` in `tools/split-rehearsal.sh` and the `^0.x` constraints between the packages in `packages/*/composer.json` (`packages/postgresql/composer.json`, `packages/amqp/composer.json` and `packages/http/composer.json`) together.

Each package measures its Infection score alone, with its own tests: the thresholds in `packages/*/infection.json5` come from the measured values (core 92, postgresql 94, amqp 97, http 94; the amqp package measures 98.0 and has few mutants, so its threshold keeps one point of margin). Raise them when the tests improve; never lower them. The root `infection.json5` (94) stays the gate for the whole code.
