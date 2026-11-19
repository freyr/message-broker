# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added

- Webhook delivery queue, core part (`src/broker/Webhook/`): the values `Subscription`, `DeliveryRecord`, `ClaimedDelivery` (with its lease value), `DeliveryPair`, `DeliveryResult` (`delivered`, `failed` with an optional retry time of the receiver, `refused` with a reason code; request time and text), `DeliveryCompletion` and `DeliveryDecision`. See `docs/core/WEBHOOKS.md`
- The webhook ports `Webhook\SubscriptionProvider` (`forMessage()`, `find()`, `lanes()`), `Webhook\DeliveryStore` (`insert()`, `claim()` with a lease, `complete()` with the lease check and the dead letters, `claimExpired()`), `Webhook\DeliverySender` (`send()`, `maxSendMs()`), `Webhook\DeliveryRetryPolicy` (`decide()`, `longestDelayMs()`) and `Webhook\DeliveryRetention` (`ensureCapacity()`, `purge()`). See `docs/core/PORTS.md`
- `Webhook\DefaultDeliveryRetryPolicy`: 8 attempts with the delays 5 s, 5 min, 30 min, 2 h, 5 h, 10 h, 10 h (about 27.5 hours); a refused result is a dead letter at once; a later retry time of the receiver replaces the delay, up to `maxReceiverDelayMs` (default 1 day)
- `Webhook\WebhookProducer::produce(Message $message, array $headers = [])`: one delivery row for each subscription of the message, in the transaction of the application, with the body bytes that `OutboxProducer` stores
- `Webhook\DeliveryLoop` with `Webhook\DeliveryLoopConfig`: the lanes at a refresh interval, with a lane filter and a random order; the claim with a row limit for each lane and a lease; no request and no attempt for a lane with no subscription; the results through the retry policy; dead letters with the source `webhook:<lane>`; the check of expired rows (default maximum age 3 days); `run(?WorkerLimits $limits = null)`; two start checks (the lease is longer than `maxSendMs()`; the maximum age plus the longest retry delay is shorter than the retention period, default 7 days); a database error stops the worker
- The events `BrokerEvents::WEBHOOK_DELIVERED` (`webhook.delivered`), `WEBHOOK_RETRIED` (`webhook.retried`), `WEBHOOK_DEAD_LETTERED` (`webhook.dead_lettered`, with `reason` and `reason_code`) and `WEBHOOK_EXPIRED` (`webhook.expired`)
- Test kit: `Testing\InMemoryDeliveryStore`, `Testing\FakeDeliverySender`, `Testing\InMemorySubscriptionProvider` and `Testing\DeliveryStoreContractTestCase` (abstract methods `createStore()` and `deadLetterStore()`). The in-memory store passes the contract test case. See `docs/core/TESTING.md`
- PostgreSQL adapter: `PostgreSqlDeliverySchemaInstaller` (a second `Storage\SchemaInstaller`) creates the table `webhook_deliveries` (primary key `(id, created_at)`, partial index `idx_webhook_deliveries_due` on `(lane, available_at) WHERE completed_at IS NULL`, one partition for each UTC day, no default partition). An application that does not use webhooks runs no new DDL. See `docs/postgresql/WEBHOOKS.md`
- PostgreSQL adapter: `PostgreSqlDeliveryStore` implements `Webhook\DeliveryStore`: a claim in one statement with a row limit for each lane (a `LATERAL` index scan for each lane), a total limit in the order of the lane list, `FOR UPDATE SKIP LOCKED` and the lease in the database time; `complete()` with the lease check and the dead letters (open row rule, source `webhook:<lane>`) in one transaction; `claimExpired()` for due rows only. It passes `DeliveryStoreContractTestCase`, also through pgbouncer (CI job `pgbouncer`, `make test-pgbouncer`)
- PostgreSQL adapter: `PostgreSqlDeliveryRetention` implements `Webhook\DeliveryRetention` with the rules of the outbox retention: partitions ahead, `DETACH PARTITION ... CONCURRENTLY`, and an expired partition with an unfinished row is kept and reported (no option to drop it)
- `message-broker:webhook:setup --premake-days=N` (`Console\WebhookSetupCommand`, takes a `Storage\SchemaInstaller`, for example `PostgreSqlDeliverySchemaInstaller`) creates the table `webhook_deliveries` and its daily partitions. `message-broker:webhook:maintain` (`Console\WebhookMaintainCommand`, takes a `Webhook\DeliveryRetention`) has the options `--retention-days` (default 7; use the retention period of the delivery loop), `--premake-days` (default 3) and `--dry-run`, and the exit codes of `outbox:maintain`: a kept partition (unfinished rows) gives exit code 1, a dry run always 0, an invalid value 2. It has no option that drops unfinished rows. The library registers no command; see `docs/core/CLI_REFERENCE.md`
- `message-broker:webhook:run` (`Console\WebhookRunCommand`) runs the `Webhook\DeliveryLoop`. It takes a factory `callable(DeliveryLoopConfig): DeliveryLoop` and a base `DeliveryLoopConfig`; the options `--lane` (repeatable lane filter), `--batch-size` and `--lane-limit` (row limit for each lane) replace their fields of the base config. `--limit` (delivered rows), `--time-limit` and `--memory-limit` give one `Worker\WorkerLimits` to `DeliveryLoop::run()`, and `--require-signals` and the shutdown warning work as in `relay:run`. A limit stop exits with code 0, an invalid value with 2
- Replay of a webhook dead letter: `ReplayService` takes the optional last constructor argument `?Webhook\DeliveryStore $deliveries = null` (an application with no webhooks constructs it as before). `replay()` of a dead letter whose source starts with `webhook:` inserts a new delivery row for that lane, with the message ID, the message name and the body bytes of the dead letter (no decode), attempts 0, and the headers without the strip list of `BrokerHeader` and with no `x-replay-target`; the conditional `markReplayed()` is the first write and the delivery insert the second, in the one transaction of the service. The lane argument (`dlq:replay --lane`) has no effect on it. `check()` (`dlq:replay --dry-run`) of such a dead letter does not read the body. A service with no delivery store, or a source with no valid lane, refuses with `NotReplayable`. Each other dead letter keeps the replay to the outbox. `replay()` and `check(string $deadLetterId, string $lane = 'default')` return a new `DeadLetter\ReplayDestination` (`lane`, `webhook`) in place of `void`. `dlq:replay` names the webhook lane of a webhook dead letter (also on `--dry-run`), and the `--all` summary adds one line that counts the webhook replays and names their lanes; the output of an outbox replay does not change. New: `BrokerHeader::withoutReplayStrip()`
- HTTP adapter, the fourth package `freyr/message-broker-http` (`src/adapter/http/`, namespace `Freyr\MessageBroker\Adapter\Http\`; the root package declares `replace` for it, and it declares `freyr/message-broker-webhook-sender-implementation` in `provide`). It needs `symfony/http-client` ^8.1 and `symfony/http-foundation` ^8.1 (in `require-dev` and `suggest` of the root package). `HttpDeliverySender(HttpSenderConfig $config = new HttpSenderConfig(), ?HttpClientInterface $client = null, LoggerInterface $logger = new NullLogger(), ?Closure $resolver = null)` implements `Webhook\DeliverySender`: the requests of one `send()` call start in parallel as `POST` with the stored body bytes; with the private network check on, the sender resolves each new host name itself (one after the other, inside the DNS allowance of the call) and pins the address for the request; a host that does not resolve, or a pair after the allowance is used up, fails with the category `dns` and sends no request; one deadline covers the transfers (the total limit, and never later than `maxSendMs()` after the start of `send()`), and a request still open then fails with the category timeout; `maxSendMs()` is the total limit plus the DNS allowance (a lookup of the operating system cannot be interrupted, so one slow host can still make `send()` late, by at most two resolver timeouts of the operating system: the A lookup, then the AAAA lookup); an internationalized host name is looked up and pinned in its ASCII form, as Symfony HttpClient sends it (needs `ext-intl` or `symfony/polyfill-intl-idn`); no redirect is followed; `verify_peer`, `verify_host` and the proxy options are set on each request (the proxy environment variables are ignored); `NoPrivateNetworkHttpClient` decorates the client unless the configuration switches it off or names a proxy; the client is reset at an interval. Each request has `content-type: application/json`, `webhook-id`, `webhook-timestamp`, `webhook-signature`, `x-message-name`, `x-created-at` and `User-Agent`, and no produce-time header; a CR, LF or NUL byte in the message ID or the message name is sent percent-encoded. One debug log line for each request has the lane, the delivery ID, the status, the result kind and the time
- `Adapter\Http\StandardWebhooksSigner`: the `webhook-` headers of the Standard Webhooks specification (`v1,` plus the base64 HMAC-SHA256 of `id.timestamp.body`), one signature for each secret of the subscription, separated by a space; a secret has the form `whsec_` plus base64. The header names and the signature form follow the specification; a change of them is a minor release
- `Adapter\Http\ResponseClassifier`: 2xx is delivered; 410 is refused with the reason code `gone`; 429 and 503 are failed with the retry time of `Retry-After` (seconds or an HTTP date; a date with an out-of-range field gives no retry time); each other status (also 3xx and the other 4xx) and each transport error are failed. Before the request it refuses for all time an invalid URL, a URL with credentials, a scheme that is not permitted (`http` only with `allowHttp`), a host that is an IP literal in a private network (`invalid-destination`) and a secret of a wrong form (`invalid-secret`). The text is `HTTP <status>` and the first 500 bytes of the body (valid UTF-8, no NUL byte), or the exception class and one category (`timeout`, `connect`, `dns`, `tls`, `blocked`); it never holds the URL, a secret or the exception message
- `Adapter\Http\HttpSenderConfig`: `totalTimeoutMs` (10 000), `connectTimeoutMs` (3 000), `dnsAllowanceMs` (5 000; 1 or more when the private network check is on), `maxHostConnections` (100, for the client that the sender creates; use the batch size of the loop or more), `clientResetIntervalMs` (300 000), `proxy` (null), `blockPrivateNetworks` (true), `allowHttp` (false) and `userAgent` (`freyr-message-broker-http`)
- `Webhook\DeliveryStore::abandon(int $before, ?array $lanes, int $limit): int`: at most `$limit` unfinished rows with `created_at` earlier than `$before`, of the given lanes or of all lanes, go to the dead letters with the error class `abandoned` and the source `webhook:<lane>` (open row rule) and get the final state dead, in one transaction; a leased row is abandoned too, and the lease check skips the late result of its worker; rows that a different session locks are skipped. `Webhook\DeliveryStore::countUnfinished(int $before, ?array $lanes): array` counts the same rows for each lane with no change. Implemented by `PostgreSqlDeliveryStore` (`FOR UPDATE SKIP LOCKED`) and `InMemoryDeliveryStore`, and stated by `DeliveryStoreContractTestCase`
- `Webhook\DeliveryDeadLetter`: the dead letter of a delivery row (source `webhook:<lane>`, the reasons `failed`, `refused` and `abandoned`), for the loop and for each store
- `message-broker:webhook:abandon --before=<date> [--lane=<lane>]... [--dry-run]` (`Console\WebhookAbandonCommand`, takes a `Webhook\DeliveryStore`, an optional `BrokerEvents` and logger, and the batch size, default 1 000): counts the old unfinished rows of each lane, abandons them in batches until a call finds no row, and prints the count of each lane; a dry run prints the counts and changes nothing. `--before` is a date (midnight UTC) or an ISO 8601 time. Exit codes 0 and 2. See `docs/core/CLI_REFERENCE.md`
- The event `BrokerEvents::WEBHOOK_ABANDONED` (`webhook.abandoned`, context `lane` and `count`), one for each lane of `webhook:abandon`
- Webhook flow control, core part: the ports `Webhook\RateLimiter` (`acquire(int $wanted): int`, `giveBack(int $count): void`), `Webhook\CircuitBreaker` (`openLanes(): list<string>`, `record(list<LaneCounts> $counts): LaneTransitions`) and `Webhook\LaneStates` (`all(): list<LaneState>`, `close(string $lane): bool`), with the values `Webhook\LaneCounts` (`lane`, `requests`, `failures`), `Webhook\LaneTransitions` (`left`, `returned`) and `Webhook\LaneState` (`lane`, `windowStartedAt`, `requests`, `failures`, `openUntil`, `openedCount`). `Webhook\NoRateLimit` and `Webhook\NoCircuitBreaker` limit nothing. See `docs/core/WEBHOOKS.md`, "Flow control"
- `Webhook\CircuitBreakerPolicy`: `windowMs` (300 000), `minimumRequests` (10), `errorRatePercent` (70), `openMs` (600 000) and `slowLimitMs` (5 000; 0 turns the slow rule off), with `trips()` and `countsAsFailure()` (each result that is not delivered, and a delivered result above the slow limit)
- `Webhook\DeliveryLoop` takes the optional last constructor arguments `rateLimiter` (default `NoRateLimit`), `circuitBreaker` (default `NoCircuitBreaker`) and `breakerPolicy` (default `new CircuitBreakerPolicy()`). Each pass removes the open lanes, takes permits for its batch size, claims at most the grant, gives the unused permits back, and after the result write gives the requests and the failures of each lane to `record()` in one call; a slow delivered result stays delivered. With a grant of 0 the loop sleeps `permitWaitMs` through `symfony/clock` and does not claim. A database error of either port stops the worker. `DeliveryLoopConfig` has the new fields `permitWaitMs` (50) and `burstCapacity` (null); when `burstCapacity` is set, `run()` refuses a larger batch size
- The events `BrokerEvents::WEBHOOK_LANE_OPENED` (`webhook.lane_opened`), `WEBHOOK_LANE_RETURNED` (`webhook.lane_returned`), each with `lane` and one of each kind for each trip, and `WEBHOOK_PERMIT_WAIT` (`webhook.permit_wait`, with `wanted` and `sleep_ms`)
- Test kit: `Testing\InMemoryRateLimiter`, `Testing\InMemoryCircuitBreaker` (also a `LaneStates`), and the contract test cases `Testing\RateLimiterContractTestCase` (`createRateLimiter()`), `Testing\CircuitBreakerContractTestCase` (`createCircuitBreaker()`, and the hook `recordAtTheSameTime()` for two `record()` calls at the same time whose lanes are in opposite order) and `Testing\LaneStatesContractTestCase` (`createCircuitBreaker()`). The fakes pass them. See `docs/core/TESTING.md`

### Removed

- **Breaking:** a webhook delivery has no time limit. `Webhook\DeliveryStore::claimExpired()`, the check of expired rows of `DeliveryLoop` with `DeliveryLoop::REASON_EXPIRED` and the dead letter reason `expired`, the `DeliveryLoopConfig` parameters `maxAgeMs`, `expiredCheckIntervalMs` and `retentionDays`, the start check that compared the maximum age plus the longest retry delay with the retention period, `Webhook\DeliveryRetryPolicy::longestDelayMs()` (also on `DefaultDeliveryRetryPolicy`) and the event `BrokerEvents::WEBHOOK_EXPIRED` (`webhook.expired`) are removed. End old rows with `message-broker:webhook:abandon`. See `UPGRADE.md`

### Changed

- The library is documented for a Kubernetes deployment only: each worker is the only process of its container, a `Deployment` starts the container again after each exit (`restartPolicy: Always`, also after the exit code `0` of a limit stop), and HPA or KEDA changes the number of pods. `docs/core/PRODUCTION.md` gets the restart delay of the kubelet and its effect on the worker limits, the termination grace period, and a part "Scheduled commands" (`CronJob` for `outbox:maintain`, `webhook:maintain`, `dedup:cleanup` and `dlq:import`). The instructions for systemd and Supervisor are removed
- The warning of `Console\SignalSupport` (no `ext-pcntl`) now starts with the PID 1 case (Kubernetes sends SIGKILL at the end of the termination grace period), names the webhook pass and its lease, and asks to load `ext-pcntl` in the container image; it no longer names `docker run --init`

## [0.12.0] - 2026-11-19

### Added

- Worker limits for `message-broker:consume`: the options `--limit` (deliveries), `--time-limit` (seconds), `--memory-limit` (`128M`, binary units) and `--failure-limit` (handler failures: deliveries that the retry policy retried, dead-lettered or discarded, a total for the run). The command builds a `Worker\WorkerLimits` and gives it to the registered callable, so the type of the callables is now `callable(WorkerLimits): void`; a callable with no parameter still runs, with no limit, and the command prints a warning when a limit is set. An invalid value gives exit code 2. A limit stop of the consumer is a graceful stop (the return rule applies) and the command exits with `0`, so the supervisor must restart after exit code `0` too (see `docs/core/PRODUCTION.md`, "Worker limits")
- `Consumer\DeliveryOutcome`: the result of one delivery (`Dispatched`, `Deduplicated`, `Malformed`, `Retried`, `DeadLettered`, `Discarded`, `DeliveryLimitExceeded`, `ReplaySkipped`), with `isHandlerFailure()` (true only for `Retried`, `DeadLettered` and `Discarded`). `DeliveryProcessor::process()` returns it instead of `void`; a caller that ignores the return value is not affected

### Changed

- **Breaking:** `AmqpConsumer::run(?WorkerLimits $limits = null, ?int $idleTimeoutSec = null)` replaces `run(?int $messageLimit = null, ?int $idleTimeoutSec = null)`. Replace `run(messageLimit: 5)` with `run(new WorkerLimits(maxMessages: 5))`, and `run(5, 10)` with `run(new WorkerLimits(maxMessages: 5), 10)`. The consumer checks the limits after each delivery and after each wait with no delivery; with a time limit, no wait is longer than the time that is left. A limit stop logs one `info` line ("Worker limit reached; the worker stops") to the logger of the consumer and is a graceful stop. See `UPGRADE.md` and `docs/amqp/CONSUMER.md`

### Fixed

- The limit options of `message-broker:relay:run` and `message-broker:consume` (`--limit`, `--time-limit`, `--memory-limit`, `--failure-limit`) and `Console\ByteSize::toBytes()` refuse a value with a trailing newline (`"5\n"`, `"128M\n"`): their patterns now end with `\z`, not `$`. Before, such a value was accepted

## [0.11.1] - 2026-11-19

### Added

- `DeadLetter\DeadLetterSink`: the write port of a dead letter destination, with one method `store(DeadLetter $deadLetter): void`. `DeadLetterStore` extends it, with no change of its methods. The parameter `deadLetters` of `DeliveryProcessor` now takes a `DeadLetterSink` (the type widens; a `DeadLetterStore` still fits, and the order of the parameters does not change). A sink that throws gives no ack and no `DEAD_LETTERED` event. See `docs/core/PORTS.md`
- `Testing\DeadLetterSinkContractTestCase` (abstract methods `createSink()` and `storedDeadLetters()`): the contract of `DeadLetterSink`. `InMemoryDeadLetterStore`, `PostgreSqlDeadLetterStore` and `AmqpDeadLetterSink` pass it. See `docs/core/TESTING.md`
- `DeadLetter\DeadLetterHeader` (`encode(DeadLetter): string`, `decode(mixed): ?array`): the value of the header `x-dead-letter`, one JSON object with the ten values of a dead letter. `encode()` cuts the error message to 1024 bytes and the error trace to 16384 bytes at a UTF-8 character boundary; `decode()` never throws and returns `null` for a value that is not a valid header
- `DeadLetterImporter` imports a message with a valid header `x-dead-letter` as the dead letter of the header (case 1): the same `id` and fields, the body of the message, and the headers without `x-dead-letter`. Case 1 has priority over a move of the transport. A header that does not decode counts as absent, and the error message gets `; x-dead-letter header did not decode`. See `docs/core/DLQ_OPERATIONS.md`
- `Adapter\Amqp\AmqpDeadLetterSink` (`AMQPChannel`, `AmqpDeadLetterConfig`): a dead letter sink that publishes each dead letter as a persistent, mandatory message with the header `x-dead-letter` and returns only after the publisher confirm; a nack, a return or a confirm timeout throws. It needs a channel that no other publisher uses. `Adapter\Amqp\AmqpDeadLetterConfig` (`exchange`, `routingKey`, `confirmTimeoutSec = 5`). See `docs/amqp/DEAD_LETTERS.md`

## [0.11.0] - 2026-11-19

### Added

- `DeadLetter\AlreadyReplayed`, `DeadLetter\NotReplayable` and `DeadLetter\DeadLetterNotFound` (each `final`, extends `RuntimeException`): the refusals of `ReplayService`. `DeadLetterNotFound` replaces the plain `RuntimeException` of an unknown ID
- `ReplayService::check(string $deadLetterId)`: the read-only checks of a replay (found, not replayed, replayable, canonical document); `dlq:replay <id> --dry-run` calls it
- `Freyr\MessageBroker\BrokerHeader`: the names of the library headers, `REPLAY_TARGET` (`x-replay-target`) and `DEAD_LETTER` (`x-dead-letter`), and the strip list of a replay (`REPLAY_STRIP`, `REPLAY_STRIP_PREFIXES`, `forReplay()`). The header names are part of the wire contract from this release
- The event `BrokerEvents::REPLAY_SKIPPED` (`message.replay_skipped`), recorded by `DeliveryProcessor` for a replay for a different queue, with `consumer`, `source`, `target`, `message_id` and `message_name`. The event `REPLAYED` has the new field `target`
- `dlq:replay --all` takes `--limit <n>` (the maximum number of rows that the run handles) and `--rate <n>` (the maximum number of replays that start in one second, with `Clock::get()->sleep()`). A value that is not a positive integer ends the command with exit code 2
- New contract cases for `markReplayed()` in `DeadLetterStoreContractTestCase` (an open row gives `true`; a second call gives `false` and keeps the first mark; an unknown ID and a value that is not a UUID give `false`), and in `TransactionContractTestCase` a new `markReplayed()` after a rolled-back mark gives `true`

### Changed

- **Breaking:** `ReplayService` replays one dead letter in one transaction of the `Storage\Transaction` port: the conditional mark first, then the outbox insert, then the commit; a mark that returns `false` rolls back and throws `AlreadyReplayed`. Its constructor takes `Transaction $transaction` as the third argument: `(DeadLetterStore, OutboxStore, Transaction, WireFormat, ?BrokerEvents)`; the two stores and the transaction must work on one connection. A replayed row throws `AlreadyReplayed` and a row with `replayable` false or a body that is not the canonical document throws `NotReplayable`, before the transaction. Each other throwable propagates after the rollback. The class is no longer `readonly`: it gives each outbox row the later of now and the last `created_at` + 1 ms. See `UPGRADE.md`
- **Breaking:** `DeadLetterStore::markReplayed(string $id): bool` is conditional: it sets the mark only when the row exists and `replayed_at` is null, and returns `true` only when this call set it. `PostgreSqlDeadLetterStore` runs `UPDATE ... AND replayed_at IS NULL` and returns `false` with no query for a value that is not a UUID; `InMemoryDeadLetterStore` follows the same rule
- **Breaking:** the outbox row of a replay has the header `x-replay-target` with the `source` of the dead letter, and the replay strips `x-replay-target`, `x-delivery-count`, `x-death`, `x-first-death-*`, `x-last-death-*` and `x-dead-letter` in addition to `x-attempt` and the envelope headers. The dead letter row keeps its headers
- **Breaking:** `DeliveryProcessor::process()` first checks the replay target: when `x-replay-target` is present and is not `Delivery::$source`, it acks with no deserialize, no transaction, no deduplication row, no dispatch and no dead letter, before the delivery limit check. Upgrade all consumers before the first replay. See `docs/core/CONSUMER.md`
- **Breaking:** `dlq:replay <id>` of a row that is not found, replayed or not replayable prints the ID and the reason and exits with code 1. `dlq:replay --all` reads only rows with `replayable = true` (unless `--not-replayable`) that failed before the start of the run (or before an earlier `--until`), works in rounds over `ids()`, skips a row that it cannot replay, continues after a failure, prints `Replayed N, skipped N (not replayable), skipped N (already replayed), failed N into lane '...'.`, and exits with code 1 when a row failed. See `docs/core/DLQ_OPERATIONS.md`

## [0.10.2] - 2026-11-19

### Fixed

- `DeadLetterStoreContractTestCase::testIdsAreOldestFirstAndThenByIdAscending` gives its two rows of the same time one `failedAt` value. Before, it read the clock two times, so the test failed when a millisecond passed between the two reads

## [0.10.1] - 2026-11-19

### Added

- `DeadLetter\DeadLetterImporter` (constructor `DeadLetterStore`, `Deserializer`, `DeadLetterBuilder`; `import(Delivery $delivery, ?TransportMove $move = null): DeadLetter`) turns one message of a dead letter queue of the transport into a row of the dead letter store. A move by the transport gives a row of the source queue of the move: the canonical document and replayable when the message deserializes, else the raw bytes and not replayable. No move gives a row of the dead letter queue that is not replayable. The error class is `Consumer\DeliveryLimitExceeded` for the reason `delivery_limit`, else `DeadLetter\MovedByTransport`; the trace is empty. See `docs/core/DLQ_OPERATIONS.md`
- `DeadLetter\TransportMove` (`sourceQueue`, `reason`, `count`, `?movedAt` in epoch milliseconds): the data of a move by the transport. `DeadLetter\MovedByTransport`: the error class of an imported row whose reason is not `delivery_limit`; the importer uses only its name and never throws it
- `DeadLetter\DeadLetterBuilder`: the build of a `DeadLetter` from a delivery, extracted from `DeliveryProcessor` (canonical form, raw form and the raw identity), with the methods `canonical()`, `raw()`, `canonicalWithError()` and `rawWithError()`. `DeliveryProcessor` and `DeadLetterImporter` use it; the dead letters of `DeliveryProcessor` do not change
- `message-broker:dlq:import <name> [--limit <n>]` (`Console\DlqImportCommand`, constructor `array<string, callable(?int $limit): int>`): imports the messages of a registered dead letter queue and prints `Imported <n> dead letters from '<name>'.`. An unknown name or a limit that is not a positive integer returns exit code 2. See `docs/core/CLI_REFERENCE.md`
- `Adapter\Amqp\AmqpDeadLetterImport` (`AMQPChannel`, `string $deadLetterQueue`, `DeadLetterImporter`; `run(?int $limit = null): int`): reads an AMQP dead letter queue with `basic.get`, reads the `TransportMove` from the newest `x-death` entry whose reason is not `expired`, stores the row, then acks. A store that throws returns the message with `basic.nack` and `requeue`, and `run()` throws the same error. See the new `docs/amqp/DEAD_LETTERS.md` and the topology in `docs/amqp/TRANSPORT.md`
- `Adapter\Amqp\AmqpDeliveryMapper` (internal): the mapping `AMQPMessage` → `Delivery`, extracted from `AmqpConsumer`, which the consumer and the import share. `AmqpConsumer::deliveryFrom()` stays and delegates to it

## [0.10.0] - 2026-11-19

### Added

- `DeadLetter\DeadLetterFilter`: the filter of the dead letter port, with nine optional criteria that combine with AND (`messageName`, `source`, `errorClass`, `messageId`, `failedFromMs` inclusive, `failedUntilMs` exclusive, `minAttempts`, `replayed`, `replayable`), `isEmpty()` and `withReplayed()`. The constructor throws `InvalidArgumentException` for a `minAttempts` below 0 or a `failedFromMs` that is not lower than `failedUntilMs`
- `DeadLetter\DeadLetterStat`: one group of the dead letter statistics (message name, source, error class, count, oldest and newest `failedAt`)
- `DeadLetterStore::ids(DeadLetterFilter $filter, int $limit)` (the IDs of the matching rows, oldest first) and `DeadLetterStore::stats(DeadLetterFilter $filter)` (one `DeadLetterStat` for each group of message name, source and error class)
- `message-broker:dlq:stats` (`Console\DlqStatsCommand`): one table row for each group of the matching dead letters, with no body and no error message. See `docs/core/DLQ_OPERATIONS.md`
- `Console\DeadLetterFilterOptions`: the shared filter options of `dlq:list`, `dlq:stats`, `dlq:purge` and `dlq:replay --all`: `--name`, `--source`, `--error`, `--message-id`, `--since`, `--until` (a duration or an ISO 8601 date-time with an offset), `--min-attempts`, `--replayed` / `--not-replayed` and `--replayable` / `--not-replayable`. An invalid value ends the command with exit code 2
- `DeadLetter::$replayable` (the last constructor parameter, default `true`; also the last parameter of `DeadLetter::fromFailure()`) and the column `dead_letters.replayable`: `false` for a dead letter whose body is the raw wire bytes. `DeliveryProcessor` writes `false` for a malformed delivery and for the raw form of the delivery limit. `dlq:list` has the column `replayable` and `dlq:show` the line `replayable:`

### Changed

- **Breaking:** `DeadLetterStore::list()`, `count()` and `purge()` take a `DeadLetterFilter` in place of the separate filter parameters: `list(DeadLetterFilter $filter, int $limit = 100, int $offset = 0)`, `count(DeadLetterFilter $filter)`, `purge(DeadLetterFilter $filter)`. The port has the new methods `ids()` and `stats()`, which an implementation outside this library must add. `InMemoryDeadLetterStore::callsOf()` records the filter object as `filter`. See `UPGRADE.md`
- **Breaking:** `DeadLetterStore::store()` keeps one open row for each message ID and source. It changes nothing when a row with the same `id` exists (open or replayed); otherwise it updates the open row of the pair (the row keeps its `id` and gets the data of the new failure), or inserts a new row. A message that fails again after a replay gets a new row. `PostgreSqlDeadLetterStore::store()` is one `INSERT ... SELECT ... WHERE NOT EXISTS ... ON CONFLICT ... DO UPDATE`; `InMemoryDeadLetterStore::store()` follows the same rule, and `add()` stays a seed hook with no upsert. `DeadLetterStoreContractTestCase` has new cases for the upsert, the replayable mark, each filter field, `ids()` and `stats()`
- **Breaking:** `dead_letters` has the new column `replayable BOOLEAN NOT NULL DEFAULT TRUE` and the partial unique index `uq_dead_letters_open` on (`message_id`, `source`) `WHERE replayed_at IS NULL`. The new `store()` fails when the index is absent. An existing installation runs the migration note of `UPGRADE.md` before it deploys the new code
- **Breaking:** `dlq:purge` counts the dry run and the prompt with the full filter (also `--older-than`); the texts are `[dry-run] would delete N dead letters.` and `Delete N dead letters? [y/N]`. `--older-than` with `--until` ends with exit code 2. `dlq:replay --all` always selects the rows that are not replayed; `--replayed` with `--all` ends with exit code 2

### Fixed

- `Console\Duration` refuses a value with a trailing newline (`"7d\n"`): its pattern now ends with `\z`, not `$`. Before, `dedup:cleanup --older-than` and `dlq:purge --older-than` accepted such a value

## [0.9.1] - 2026-11-19

### Fixed

- Each text of a `DeadLetter` is now storable. The constructor (and thus `DeadLetter::fromFailure()`) makes `source`, `messageId`, `messageName`, `errorClass`, `errorMessage`, `errorTrace`, each header key and each string header value (nested too) valid UTF-8 with no NUL character: each invalid byte sequence and each NUL becomes U+FFFD. It cuts `source`, `messageId`, `messageName` and `errorClass` to 255 characters. The body stays the exact bytes. Before, a header with invalid UTF-8 made `PostgreSqlDeadLetterStore::store()` throw a `JsonException`, and a NUL character or a name above 255 characters made PostgreSQL refuse the row, so the dead letter write failed at each delivery. `DeadLetterStoreContractTestCase` has the new case `testStoresTextThatEachSinkCanStore`. See `docs/core/CONSUMER.md`
- A raw dead letter with no message ID (no envelope and no transport message ID) now gets the message ID `unknown-` and a UUID v7, a new value for each dead letter. Before, it got the literal `unknown`, so all such messages of one work queue had the same message ID. The `DEAD_LETTERED` event, the log and the `ErrorHandler` context of the delivery limit give the generated value. Rows that earlier versions wrote keep `unknown`

## [0.9.0] - 2026-11-18

### Added

- `message-broker:outbox:failing [--lane=default] [--min-attempts=3] [--limit=100]` (`Console\OutboxFailingCommand`) lists the open outbox rows of a lane with failed publish attempts, with the body size and never the body. `message-broker:outbox:abandon <id> [--reason=...]` (`Console\OutboxAbandonCommand`) abandons the open rows of a message ID, so that the relay does not publish them, and logs a warning with the reason. See `docs/core/CLI_REFERENCE.md` and the runbook in `docs/core/RELAY.md`
- `Outbox\FailingRow`, the value that `OutboxStore::failing()` returns
- The event `BrokerEvents::ABANDONED` (`message.abandoned`), recorded by `OutboxAbandonCommand` with `message_id`, `count` and `reason`
- `RetentionReport` has the new last parameter `$abandoned` (partition => count of abandoned rows that were dropped, or would be on a dry run) and `abandonedRowsDropped()`. `outbox:maintain` prints one line for each entry; its exit codes do not change
- `InMemoryOutboxStore::isAbandoned()`, and `OutboxStoreContractTestCase::record()` takes the optional parameters `id` and `createdAt`

### Changed

- **Breaking:** `Outbox\OutboxStore` has two new methods, `abandon(string $id): int` and `failing(string $lane, int $minAttempts, int $limit): array`. An implementation outside this library must add them, and its claim and outcome writes must skip an abandoned row. `PostgreSqlOutboxStore` and `InMemoryOutboxStore` implement them; `OutboxStoreContractTestCase` has new cases. See `UPGRADE.md`
- **Breaking:** `outbox_messages` has the new column `abandoned_at TIMESTAMP(3) NULL`. An existing installation adds it before it deploys the new code; `UPGRADE.md` has the migration note. `idx_outbox_pending` does not change. The claim, `markPublished()` and `scheduleRetry()` of `PostgreSqlOutboxStore` skip an abandoned row
- **Breaking:** retention does not keep a partition for an abandoned row: "unpublished" in `OutboxRetention` now means `published_at IS NULL AND abandoned_at IS NULL`, and `RetentionReport::$kept` and `$unpublished` count only the rows that are not abandoned

## [0.8.0] - 2026-11-18

### Added

- `Consumer\DeliveryLimitExceeded` (named constructor `atCount(int $count, int $limit)`): the error class of a dead letter that the delivery limit writes
- `DeliveryProcessor` takes the last constructor parameter `?int $maxDeliveries = 5`, the delivery limit. When the delivery count of the transport is at or above it, the processor writes a dead letter with the error `DeliveryLimitExceeded` and acks, with no transaction, no deduplication and no dispatch. `null` turns the limit off; a value below 1 throws `InvalidArgumentException`. See `docs/core/CONSUMER.md`
- `AmqpConsumer` takes the last constructor parameter `LoggerInterface $logger = new NullLogger()`, which receives a failure inside the return of the unacked deliveries. `psr/log` is now in the `require` section of `packages/amqp/composer.json`
- `Adapter\Amqp\WaitQueuePublisher::DELIVERY_COUNT_HEADER` (`x-delivery-count`). The retry republish to a wait queue no longer copies this header, so the first delivery after a wait-queue pass has no count

### Changed

- `docs/postgresql/CONNECTION_POOLING.md` no longer says that any pool mode works. It states the tested result: transaction mode works behind pgbouncer with `max_prepared_statements` above 0 (tested with pgbouncer 1.26.1 and the value 200; the setting exists since 1.21.0, and earlier versions than 1.26.1 are not tested); with `max_prepared_statements = 0` the library fails with `prepared statement "pdo_stmt_…" does not exist`, because pdo_pgsql sends the prepare and the execute as separate round trips. A new CI job `pgbouncer` (and `make test-pgbouncer`) runs the PostgreSQL contract tests and the consumer functional tests through pgbouncer 1.26.1 in transaction mode, with forced rotation of server connections. No code change
- **Breaking:** the delivery limit of `DeliveryProcessor` is active by default (`maxDeliveries = 5`). On a quorum queue, the sixth delivery after five deliveries that ended with no ack becomes a dead letter with the error `DeliveryLimitExceeded`, and the handler does not run. Pass `maxDeliveries: null` to keep the old behavior. See `UPGRADE.md`
- **Breaking:** `AmqpConsumer` returns its unacked deliveries at each stop that it controls. A graceful stop sends `basic.cancel` and one `basic.nack` (tag 0, `multiple`, `requeue`), which adds no delivery count. A `Throwable` sends `basic.cancel`, `basic.reject` (requeue) of the delivery in work and the same `basic.nack`, then goes on; only the delivery in work gets +1. Before, the channel close returned each unacked delivery with +1, also each buffered delivery. See `docs/amqp/CONSUMER.md`, *The return rule*
- **Breaking:** a confirm timeout of the wait-queue publish ends `AmqpConsumer::run()` with the exception, so `message-broker:consume` exits with a failure. Before, it ended `run()` as an idle stop, with the delivery unacked and exit code 0

## [0.7.6] - 2026-11-18

### Added

- `Outbox\LaneLimits` (`maxBodyBytes`, `maxHeaderBytes`, `null` means no limit) and `Outbox\MessageTooLarge`. `OutboxProducer` takes the optional last constructor parameter `?LaneLimits $limits = null` and throws `MessageTooLarge` before the insert when the encoded body or the JSON form of the headers is above the limit. The core has no default limit
- `Adapter\Amqp\AmqpLaneLimits::rabbitMqDefaults()`: the RabbitMQ limits (body 15 MiB, headers 48 KiB of JSON) as `LaneLimits`. See `docs/amqp/TRANSPORT.md`
- The log context (and the `ErrorHandler` context) of a retried row that fails alone has the keys `message_id`, `message_name` and `attempts`. A nack gives them to the logger only. The log message texts do not change

## [0.7.5] - 2026-11-18

### Added

- Worker limits for `message-broker:relay:run`: the options `--limit` (published rows), `--time-limit` (seconds) and `--memory-limit` (`128M`, binary units). The command builds a `Worker\WorkerLimits` and gives it to the registered callable, so the type of the callables is now `callable(WorkerLimits): void`; a callable with no parameter still runs, with no limit, and the command prints a warning when a limit is set. `Relay::run(?WorkerLimits $limits = null)` checks the limits at the end of each pass, logs one `info` line and stops like it does on SIGTERM; the command exits with `0`, so the supervisor must restart after exit code `0` too (see `docs/core/PRODUCTION.md`, "Worker limits"). New core classes `Worker\WorkerLimits`, `Worker\WorkerLimitGuard`, `Worker\WorkerStopReason` and `Console\ByteSize`

## [0.7.4] - 2026-11-18

### Fixed

- The warning for a missing `ext-pcntl` (and `docs/core/PRODUCTION.md`) said that only SIGKILL stops the process. Measured with no `pcntl`: SIGTERM ends the process at once when it is not PID 1 of a container, and as PID 1 the process ignores SIGTERM until the runtime sends SIGKILL after its stop timeout. The text now states both cases. `ext-pcntl` is now in `suggest` of the root, `core` and `amqp` manifests

## [0.7.3] - 2026-11-18

### Added

- `TransactionContractTestCase` has the optional hook `createDeadLetterStore()` (default `null`), which the test case calls after `createOutboxStore()`. With the hook, two cases run: a replay mark in a rolled-back transaction is gone, and a mark and an outbox insert in one committed transaction both stay. Without it, the cases are skipped. `InMemoryTransactionContractTest` and `PdoTransactionContractTest` implement the hook. See `docs/core/TESTING.md`

### Fixed

- `InMemoryOutboxStore` keys its rows by (`id`, `createdAt`), as the primary key of `outbox_messages` is (`id`, `created_at`). Before, it keyed them by `id`, so in a test the replay of a published message replaced the original row. An id in the `ClaimOutcome` applies to each claimed row with that id, as in `PostgreSqlOutboxStore`. `row()` and `isPublished()` use the row of the id with the latest `createdAt`; `unpublishedIds()` gives each id one time

## [0.7.2] - 2026-11-18

### Added

- `ExponentialRetryPolicy` takes two optional lists of throwable classes or interfaces: `deadLetterOn` (dead-letter at once, with no retry) and `discardOn` (ack and drop). An error in both lists is dead-lettered; a `discardOn` error at the last attempt is discarded. An entry that is not a throwable class throws `InvalidArgumentException`. Both lists are empty by default
- `Retry\RetryPolicyMap` selects a retry policy for each message name (exact match), with a default policy
- The events `BrokerEvents::RETRIED` (`message.retried`) and `BrokerEvents::DISCARDED` (`message.discarded`), recorded by `DeliveryProcessor`. `RETRIED` comes after the transport confirmed the retry

### Changed

- `composer.json` now has `replace` for `freyr/message-broker-postgresql` and `freyr/message-broker-amqp` (`self.version`): when the code moves to separate packages, this package will stand in for them, so no second copy of the code is installed. The `branch-alias` (`dev-main` as `1.0-dev`) is removed because no 1.0 line is planned; `dev-main` now has no alias and does not satisfy `^0.7`

## [0.7.1] - 2026-11-15

### Added

- Test kit in the core, namespace `Freyr\MessageBroker\Testing\` (`src/broker/Testing/`): the abstract contract test cases `OutboxStoreContractTestCase`, `DeduplicationStoreContractTestCase`, `DeadLetterStoreContractTestCase`, `TransactionContractTestCase` and `OutboxPublisherContractTestCase` for the authors of adapters, and the in-memory fakes `InMemoryOutboxStore`, `InMemoryDeduplicationStore`, `InMemoryDeadLetterStore`, `InMemoryTransaction` and `FakeOutboxPublisher` (and `RecordingDeliveryControl`). The test cases extend the PHPUnit `TestCase`, so `phpunit/phpunit` is now in `suggest`. `Adapter\PostgreSql\Testing\PostgreSqlTestSupport` is the PostgreSQL test set-up for the adapter tests. See `docs/core/TESTING.md`

## [0.7.0] - 2026-11-15

The code is split into a core and two adapters inside this package. `UPGRADE.md` has the full table of old and new class names.

### Changed

- **Breaking:** the code moves to three folders with separate namespaces and three PSR-4 roots: the core in `src/broker/` (`Freyr\MessageBroker\`), the PostgreSQL adapter in `src/adapter/postgresql/` (`Freyr\MessageBroker\Adapter\PostgreSql\`) and the AMQP adapter in `src/adapter/amqp/` (`Freyr\MessageBroker\Adapter\Amqp\`). The six PostgreSQL classes and the six AMQP classes change namespace; `Transport\DeduplicationStore` is now `Consumer\DeduplicationStore` and `Transport\IdleSleep` is now `Outbox\IdleSleep`. The namespace `Freyr\MessageBroker\Transport` no longer exists. There are no aliases. See `UPGRADE.md` for the full table of old and new class names and for before and after wiring examples
- **Breaking:** the SQL dialect seam `Storage\Platform` and `Storage\PostgreSqlPlatform` are removed. Each PostgreSQL class holds its own SQL, and the core talks to storage through ports only
- **Breaking:** renamed classes, no aliases (the final namespaces are in the entry on the folder move above): `Outbox\PdoOutboxStore` -> `Adapter\PostgreSql\PostgreSqlOutboxStore`, `Transport\PdoDeduplicationStore` -> `Adapter\PostgreSql\PostgreSqlDeduplicationStore`, `DeadLetter\PdoDeadLetterStore` -> `Adapter\PostgreSql\PostgreSqlDeadLetterStore`, `Outbox\OutboxPartitionManager` -> `Adapter\PostgreSql\PostgreSqlOutboxRetention`, `Outbox\PartitionPurgeReport` -> `Outbox\RetentionReport`. The three stores take no `Platform` argument any more: `new PostgreSqlOutboxStore($pdo)`, `new PostgreSqlDeduplicationStore($pdo, deleteChunkSize: ...)`, `new PostgreSqlDeadLetterStore($pdo)`
- **Breaking:** `Adapter\PostgreSql\PostgreSqlOutboxRetention::ensurePartitions($daysAhead = 3, $today = null)` is now `ensureCapacity($daysAhead, $today = null)` (no default for `$daysAhead`). `purge()` takes `(retentionDays, dryRun, dropUnpublished, today)`; `$dropUnpublished` moved before `$today`. `$today` is a test parameter of the PostgreSQL class and is not part of the port
- **Breaking:** `SetupSchemaCommand` takes a `SchemaInstaller` (was `PDO` and `Platform`). `OutboxMaintainCommand` takes an `OutboxRetention` (was the concrete partition manager). Options, output and exit codes do not change
- **Breaking:** `Transport\Amqp\AmqpRelay` is removed. The relay loop is now `Outbox\Relay` (no AMQP import) and takes an `Outbox\OutboxPublisher`. Old: `new AmqpRelay($outbox, $channel, $publishConfig, $contentType, lane: 'orders', confirmTimeoutSec: 5)`. New: `new Relay($outbox, new AmqpMessagePublisher($channel, $publishConfig, $contentType, confirmTimeoutSec: 5), lane: 'orders')`. The other arguments (`lane`, `batchSize`, `backoff`, `errorHandler`, `idleSleepMs`, `logger`, `events`) keep their names and defaults. The AMQP behavior is unchanged
- `Adapter\Amqp\AmqpMessagePublisher` implements `OutboxPublisher`; its `maxBatchSize()` returns `PHP_INT_MAX`, so one claim is one batch and one confirm wait
- **Breaking:** `AmqpConsumer` (now `Adapter\Amqp\AmqpConsumer`) is split into a transport-neutral `Consumer\DeliveryProcessor` (the exactly-once algorithm, no AMQP import) and a thin AMQP loop. The constructor of `AmqpConsumer` changes from 13 parameters to `(AMQPChannel $channel, AmqpQueueConfig $queue, DeliveryProcessor $processor, int $confirmTimeoutSec = 5)`. Pass `deserializer`, `dispatcher`, `deduplication`, `retryPolicy`, `deadLetters`, `name`, `errorHandler`, `logger` and `events` to `DeliveryProcessor`, and give it `new PdoTransaction($pdo)` as `transaction` in place of the `pdo` parameter
- **Breaking:** `Transport\Amqp\AmqpRetryPolicy` is renamed to `Retry\ExponentialRetryPolicy` (same constructor and behavior, no alias). It implements the new interface `Retry\RetryPolicy`, whose `decide(int $attempt, Throwable $error, IncomingMessage $message)` takes the message as a third parameter
- The retry republish to the TTL wait queue moves from `AmqpConsumer` to `Adapter\Amqp\WaitQueuePublisher`; the behavior is unchanged

### Added

- Port `Outbox\OutboxRetention` (`ensureCapacity()`, `purge()`) with the implementation `Adapter\PostgreSql\PostgreSqlOutboxRetention`
- Port `Storage\SchemaInstaller` (`sql()` for the DDL of a new database, `install()` to create what is missing) with the implementation `Adapter\PostgreSql\PostgreSqlSchemaInstaller`. One operation covers the tables and the first partitions
- Port `Outbox\OutboxPublisher` (`publishBatch()` returns the IDs that the transport refused, `maxBatchSize()`): a transport adapter implements it. `Relay` publishes the fresh rows of a claim in chunks of `maxBatchSize()`; a failed call backs off the rows of that call only, and later chunks stay due
- `Storage\Transaction` and `Adapter\PostgreSql\PdoTransaction`: the database transaction of one delivery
- `Consumer\Delivery` (a transport-neutral delivery, with `deliveryCount`), `Consumer\DeliveryControl` and `Adapter\Amqp\AmqpDeliveryControl`

## [0.6.0] - 2026-11-15

### Changed

- **Breaking:** `message-broker:outbox:maintain` and `OutboxPartitionManager::purge()` no longer drop an expired partition that holds unpublished rows (`published_at IS NULL`). The partition is kept and reported, and the command exits with code 1; it is dropped on a later run once its rows are published. A table that the run detached and then found to hold unpublished rows, an expired detached table, and a partition with a pending detach are attached again, so the relay can publish their rows
- `PartitionPurgeReport` has a new `kept` list (partition name to unpublished row count), `unpublishedRowsKept()` and `hasKept()`

### Added

- Option `--drop-unpublished` of `message-broker:outbox:maintain` and parameter `$dropUnpublished` of `purge()`: the previous behavior, a hard age limit that drops the partition with its unpublished rows

## [0.5.0] - 2026-11-15

The standalone library (PostgreSQL and AMQP, no framework) has the versions 0.1.0 and 0.5.0; 0.5.0 holds all changes below. The versions 0.2.0 to 0.4.0 belong to the earlier Symfony Messenger bundle of the same package name; their tags point to that separate history.

### Added

- Core transactional outbox on a partitioned PostgreSQL table (PostgreSQL 14+), with lanes as named drains and no ordering guarantee
- Partition retention: the relay marks rows published (`published_at` audit) and never deletes; `message-broker:outbox:maintain` creates daily partitions ahead (no default partition) and drops expired ones with `DETACH PARTITION ... CONCURRENTLY`; the retention period is also the age limit of an unpublished row
- Consumer-side deduplication (insert-or-ignore keyed by message id and consumer) turning at-least-once delivery into exactly-once processing
- Dead-lettering with replay capability for failed messages
- AMQP transport with a single AmqpRelay (any number of workers drain one lane in parallel via FOR UPDATE SKIP LOCKED claims, default batch 500), TTL/DLX retry support, and consumer-side dedup integration
- Consumer failure handling: the retry republish is confirmed before the ack; a database failure stops the consumer with the real error and uses no retry attempt; the dedup insert is prepared once per connection; `message-broker:dedup:cleanup` deletes in chunks with an index on `created_at`
- Relay failure handling: a broker nack backs off only the nacked rows; a channel, connection or confirm-timeout failure backs off the failed rows and stops the worker (crash-only); a retried row is published alone, so a refused message fails alone; an unroutable message is dropped by design
- PostgreSQL storage backend
- JSON wire format (`JsonWireFormat`, `JsonDeserializer`), encoded at produce time
- PSR-3 logging, BrokerEvents instrumentation events, and an ErrorHandler hook for operational observability
- Dead-letter queue and deduplication CLI with dry-run and force-execute guardrails
- Storage interfaces (OutboxStore, DeadLetterStore, DeduplicationStore) with PDO default implementations
