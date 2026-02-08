---
title: "Custom Console Command for AMQP Topology Setup from YAML Configuration"
type: feat
date: 2026-02-08
issue: 15
brainstorm: docs/brainstorms/2026-02-08-amqp-topology-setup-command-brainstorm.md
---

# Custom Console Command for AMQP Topology Setup from YAML Configuration

## Overview

Implement `message-broker:setup-amqp` — a Symfony console command that declares AMQP infrastructure (exchanges, queues, bindings) from a declarative YAML configuration integrated into the bundle's config tree. Two modes: direct execution against RabbitMQ via `ext-amqp`, and `--dump` to export a RabbitMQ-compatible definitions JSON file.

Fixes #15

## Problem Statement

The bundle uses `auto_setup: false` for AMQP transports, meaning RabbitMQ infrastructure (exchanges, queues, bindings) must exist before workers start. Currently this is managed manually or through external tooling. There is no version-controlled, reproducible way to declare the full AMQP topology from the application codebase.

The gap: Symfony Messenger's `auto_setup` cannot create cross-transport topology (DLX exchanges, DLQ queues, alternate exchanges). It only declares resources for a single transport.

## Proposed Solution

### Architecture: Service + Command

```
SetupAmqpTopologyCommand (thin command, creates connection, delegates)
  └── TopologyManager (core service, testable, reusable)
        ├── declare(\AMQPChannel): TopologyResult
        ├── dumpDefinitions(string $vhost): array
        └── dryRun(): array<TopologyAction>
```

Plus a `DefinitionsFormatter` value object for generating RabbitMQ-compatible JSON.

### YAML Configuration

Extends the existing `message_broker` config tree with `amqp.topology`:

```yaml
message_broker:
    inbox:
        # ... existing config ...
    amqp:
        topology:
            exchanges:
                commerce:
                    type: topic
                    durable: true
                    arguments:
                        alternate-exchange: unrouted
                dlx:
                    type: direct
                    durable: true
                unrouted:
                    type: fanout
                    durable: true

            queues:
                orders_queue:
                    durable: true
                    arguments:
                        x-dead-letter-exchange: dlx
                        x-dead-letter-routing-key: dlq.orders
                        x-queue-type: quorum
                        x-delivery-limit: 5
                dlq.orders:
                    durable: true
                unrouted_queue:
                    durable: true

            bindings:
                - exchange: commerce
                  queue: orders_queue
                  binding_key: 'order.*'
                - exchange: dlx
                  queue: dlq.orders
                  binding_key: 'dlq.orders'
                - exchange: unrouted
                  queue: unrouted_queue
```

## Technical Approach

### File Structure

```
src/
├── Amqp/
│   ├── TopologyManager.php           # Core service: declare, dry-run, dump
│   └── DefinitionsFormatter.php      # RabbitMQ definitions JSON formatter
├── Command/
│   └── SetupAmqpTopologyCommand.php  # Console command (thin)
└── DependencyInjection/
    ├── Configuration.php             # Extended with amqp.topology node
    └── FreyrMessageBrokerExtension.php  # Passes topology config as parameter
```

### Implementation Phases

#### Phase 1: Configuration Tree (`Configuration.php` + `FreyrMessageBrokerExtension.php`)

Extend the existing config tree with validated `amqp.topology` section.

**Tasks:**
- [ ] Add `amqp` → `topology` → `exchanges` node (map, keyed by name)
  - `type`: enum — `direct`, `fanout`, `topic`, `headers` (required)
  - `durable`: boolean, default `true`
  - `arguments`: free-form map, default `{}`
- [ ] Add `amqp` → `topology` → `queues` node (map, keyed by name)
  - `durable`: boolean, default `true`
  - `arguments`: free-form map, default `{}`
- [ ] Add `amqp` → `topology` → `bindings` node (array of maps)
  - `exchange`: string (required)
  - `queue`: string (required)
  - `binding_key`: string, default `''`
  - `arguments`: free-form map, default `{}`
- [ ] Update `FreyrMessageBrokerExtension` to set `message_broker.amqp.topology` parameter
- [ ] Ensure empty topology is valid (all three sections optional/empty by default)

**Reference files:**
- `src/DependencyInjection/Configuration.php:20-44` — existing tree pattern
- `src/DependencyInjection/FreyrMessageBrokerExtension.php:17-33` — parameter setting pattern

**Config validation rules:**
- Exchange `type` must be one of: `direct`, `fanout`, `topic`, `headers`
- Binding `exchange` must reference a defined exchange name
- Binding `queue` must reference a defined queue name
- Integer normalisation for queue arguments: `x-message-ttl`, `x-max-length`, `x-max-length-bytes`, `x-max-priority`, `x-expires`, `x-delivery-limit`

#### Phase 2: TopologyManager Service (`src/Amqp/TopologyManager.php`)

Core logic: topological sort, declaration, dry-run, dump.

**Tasks:**
- [ ] Create `Freyr\MessageBroker\Amqp\TopologyManager` as `final readonly class`
- [ ] Constructor: accepts topology config array + optional `LoggerInterface`
- [ ] Implement exchange dependency resolution:
  - Scan exchange `arguments` for `alternate-exchange` references
  - Scan queue `arguments` for `x-dead-letter-exchange` references
  - Build dependency graph, topological sort
  - Throw `\RuntimeException` if cycle detected (should never happen in practice)
- [ ] Implement `declare(\AMQPChannel $channel): array` method:
  - Declare exchanges in dependency order using `\AMQPExchange`
  - Declare queues using `\AMQPQueue`
  - Create bindings using `\AMQPQueue::bind()`
  - Return array of action results (name, type, status: created/exists/error)
  - Catch `\AMQPExchangeException` / `\AMQPQueueException` for PRECONDITION_FAILED
  - Continue on error (don't abort after first failure)
- [ ] Implement `dryRun(): array` method:
  - Return list of planned actions without connecting to RabbitMQ
  - Format: `['Declare exchange "commerce" (topic, durable)', ...]`
- [ ] Implement `dumpDefinitions(string $vhost = '/'): array` method:
  - Return RabbitMQ-compatible definitions structure
  - Map `binding_key` → `routing_key` in output
  - Include `vhost` field on each entry
  - Exchanges: `name`, `vhost`, `type`, `durable`, `auto_delete` (false), `internal` (false), `arguments`
  - Queues: `name`, `vhost`, `durable`, `auto_delete` (false), `arguments`
  - Bindings: `source`, `vhost`, `destination`, `destination_type` (queue), `routing_key`, `arguments`

**ext-amqp API usage:**
```php
// Exchange declaration
$exchange = new \AMQPExchange($channel);
$exchange->setName('commerce');
$exchange->setType(AMQP_EX_TYPE_TOPIC);
$exchange->setFlags(AMQP_DURABLE);
$exchange->setArguments(['alternate-exchange' => 'unrouted']);
$exchange->declareExchange();

// Queue declaration
$queue = new \AMQPQueue($channel);
$queue->setName('orders_queue');
$queue->setFlags(AMQP_DURABLE);
$queue->setArguments([
    'x-dead-letter-exchange' => 'dlx',
    'x-queue-type' => 'quorum',
    'x-delivery-limit' => 5,
]);
$queue->declareQueue();

// Queue-to-exchange binding
$queue->bind('commerce', 'order.*');
```

**Reference files:**
- `tests/Functional/FunctionalTestCase.php` — test AMQP setup pattern (uses php-amqplib, but same concepts)

#### Phase 3: DefinitionsFormatter (`src/Amqp/DefinitionsFormatter.php`)

Formats topology config into RabbitMQ definitions JSON structure.

**Tasks:**
- [ ] Create `Freyr\MessageBroker\Amqp\DefinitionsFormatter` as `final readonly class`
- [ ] Constructor: accepts topology config array
- [ ] Implement `format(string $vhost = '/'): array` method
- [ ] Map exchange config → RabbitMQ exchange definition
- [ ] Map queue config → RabbitMQ queue definition
- [ ] Map binding config → RabbitMQ binding definition (`binding_key` → `routing_key`)
- [ ] Ensure integer arguments remain integers in JSON output

**Output example:**
```json
{
    "exchanges": [
        {"name": "commerce", "vhost": "/", "type": "topic", "durable": true, "auto_delete": false, "internal": false, "arguments": {"alternate-exchange": "unrouted"}}
    ],
    "queues": [
        {"name": "orders_queue", "vhost": "/", "durable": true, "auto_delete": false, "arguments": {"x-dead-letter-exchange": "dlx", "x-queue-type": "quorum", "x-delivery-limit": 5}}
    ],
    "bindings": [
        {"source": "commerce", "vhost": "/", "destination": "orders_queue", "destination_type": "queue", "routing_key": "order.*", "arguments": {}}
    ]
}
```

#### Phase 4: SetupAmqpTopologyCommand (`src/Command/SetupAmqpTopologyCommand.php`)

Thin console command that creates AMQP connection and delegates to TopologyManager.

**Tasks:**
- [ ] Create `Freyr\MessageBroker\Command\SetupAmqpTopologyCommand` as `final class` (extends `Command`)
- [ ] `#[AsCommand(name: 'message-broker:setup-amqp', description: 'Declare AMQP topology from configuration')]`
- [ ] Constructor: inject `TopologyManager`, `DefinitionsFormatter`, optional `?string $defaultDsn = null`
- [ ] Options:
  - `--dsn` — AMQP connection DSN (default: `%env(MESSENGER_AMQP_DSN)%`)
  - `--dry-run` — show planned actions without executing
  - `--dump` — output RabbitMQ definitions JSON instead of executing
  - `--output` — file path for `--dump` output (default: stdout)
  - `--vhost` — override vhost for `--dump` (default: extracted from DSN)
- [ ] DSN parsing: `parse_url()` to extract host, port, user, pass, vhost
- [ ] Create `\AMQPConnection` from parsed DSN components
- [ ] `--dry-run` mode: call `TopologyManager::dryRun()`, display actions, exit
- [ ] `--dump` mode: call `DefinitionsFormatter::format()`, JSON encode, write to stdout or file
- [ ] Default mode: call `TopologyManager::declare()`, display results with SymfonyStyle
- [ ] Output formatting:
  - `[OK] Declared exchange "commerce" (topic, durable)`
  - `[SKIP] Queue "orders_queue" already exists`
  - `[ERROR] Exchange "commerce" exists with different settings`
- [ ] Return `Command::SUCCESS` if no errors, `Command::FAILURE` if any declaration failed

**Reference files:**
- `src/Command/DeduplicationStoreCleanup.php` — command pattern

#### Phase 5: Service Registration

**Tasks:**
- [ ] Register `TopologyManager` in `config/services.yaml`:
  ```yaml
  Freyr\MessageBroker\Amqp\TopologyManager:
      arguments:
          $topology: '%message_broker.amqp.topology%'
          $logger: '@logger'
  ```
- [ ] Register `DefinitionsFormatter` in `config/services.yaml`:
  ```yaml
  Freyr\MessageBroker\Amqp\DefinitionsFormatter:
      arguments:
          $topology: '%message_broker.amqp.topology%'
  ```
- [ ] Register `SetupAmqpTopologyCommand` in `config/services.yaml`:
  ```yaml
  Freyr\MessageBroker\Command\SetupAmqpTopologyCommand:
      arguments:
          $topologyManager: '@Freyr\MessageBroker\Amqp\TopologyManager'
          $definitionsFormatter: '@Freyr\MessageBroker\Amqp\DefinitionsFormatter'
          $defaultDsn: '%env(MESSENGER_AMQP_DSN)%'
      tags: ['console.command']
  ```

**Reference files:**
- `config/services.yaml:65-71` — existing command registration

#### Phase 6: Unit Tests

**Tasks:**
- [ ] `tests/Unit/Amqp/TopologyManagerTest.php`:
  - Test exchange dependency resolution (topological sort)
  - Test cycle detection throws `\RuntimeException`
  - Test `dryRun()` returns correct action list
  - Test `dumpDefinitions()` returns correct structure
  - Test integer normalisation for queue arguments
  - Test empty topology (no exchanges, no queues, no bindings)
- [ ] `tests/Unit/Amqp/DefinitionsFormatterTest.php`:
  - Test `binding_key` → `routing_key` mapping
  - Test vhost is applied to all entries
  - Test `auto_delete: false` and `internal: false` defaults for exchanges
  - Test integer arguments preserved (not cast to string)
  - Test empty topology produces empty arrays
- [ ] `tests/Unit/DependencyInjection/ConfigurationTest.php`:
  - Test valid topology config passes validation
  - Test invalid exchange type is rejected
  - Test defaults are applied (durable: true, arguments: {})
  - Test binding_key defaults to empty string
  - Test empty topology is valid

#### Phase 7: Functional Tests

**Tasks:**
- [ ] `tests/Functional/Command/SetupAmqpTopologyCommandTest.php`:
  - Test declaring a complete topology against live RabbitMQ (Docker)
  - Test idempotency: running twice produces same result
  - Test `--dry-run` outputs planned actions without connecting
  - Test `--dump` outputs valid RabbitMQ definitions JSON
  - Test `--dump --output=file` writes to file
  - Test missing DSN produces clear error message
  - Add topology config to `tests/Functional/config/test.yaml`
- [ ] Update `tests/Functional/config/test.yaml` with test topology:
  ```yaml
  message_broker:
      amqp:
          topology:
              exchanges:
                  test_events:
                      type: topic
                      durable: true
              queues:
                  test_inbox:
                      durable: true
              bindings:
                  - exchange: test_events
                    queue: test_inbox
                    binding_key: 'test.#'
  ```

**Reference files:**
- `tests/Functional/FunctionalTestCase.php` — base class pattern
- `tests/Functional/config/test.yaml` — test config structure

#### Phase 8: Documentation

**Tasks:**
- [ ] Update `README.md` with AMQP topology setup section:
  - YAML configuration reference
  - Command usage examples (direct, dry-run, dump)
  - RabbitMQ definitions JSON import instructions
- [ ] Update `CLAUDE.md` architecture section if needed

## Acceptance Criteria

### Functional Requirements
- [ ] YAML configuration schema defined and validated via Symfony Config component
- [ ] Console command declares exchanges, queues, and bindings against live RabbitMQ
- [ ] Console command supports `--dry-run` mode showing planned actions
- [ ] Console command supports `--dump` mode generating RabbitMQ definitions JSON
- [ ] Console command supports `--output` option for dump file path
- [ ] Idempotent execution — running twice produces the same result
- [ ] Dependency ordering — exchanges referenced in arguments declared first
- [ ] `binding_key` used in YAML, mapped to `routing_key` in RabbitMQ JSON

### Non-Functional Requirements
- [ ] Follows existing codebase conventions (`final class`, explicit DI, British English)
- [ ] Unit tests for TopologyManager, DefinitionsFormatter, Configuration
- [ ] Functional test covering topology declaration against RabbitMQ (Docker)
- [ ] ECS formatting passes
- [ ] PHPStan passes

### Quality Gates
- [ ] All existing tests continue to pass
- [ ] New tests pass in CI (Docker Compose with RabbitMQ)
- [ ] `docker compose run --rm php vendor/bin/phpunit` green
- [ ] `docker compose run --rm php vendor/bin/ecs check` green

## Dependencies & Prerequisites

- `ext-amqp` — already in production `require`
- Docker Compose with RabbitMQ — already in test infrastructure
- No new Composer dependencies needed

## Risk Analysis

| Risk | Mitigation |
|------|------------|
| First direct ext-amqp usage in `src/` | Well-documented API, same concepts as php-amqplib used in tests |
| PRECONDITION_FAILED on settings mismatch | Catch exception, report clearly, continue |
| Integer/string type confusion in arguments | Explicit normalisation in Configuration validation |
| Topology config grows complex | Keep scope narrow (no E2E bindings, no policies) |

## References

### Internal References
- Brainstorm: `docs/brainstorms/2026-02-08-amqp-topology-setup-command-brainstorm.md`
- Research: `docs/brainstorms/2026-02-08-override-messenger-auto-setup-amqp-topology-brainstorm.md`
- Existing command: `src/Command/DeduplicationStoreCleanup.php`
- Config pattern: `src/DependencyInjection/Configuration.php`
- Service wiring: `config/services.yaml`
- Test base: `tests/Functional/FunctionalTestCase.php`
- Test config: `tests/Functional/config/test.yaml`
- Critical patterns: `docs/solutions/patterns/critical-patterns.md`

### External References
- RabbitMQ definitions format: https://www.rabbitmq.com/docs/definitions
- RabbitMQ HTTP API: https://www.rabbitmq.com/docs/http-api-reference
- PHP AMQP extension stubs: https://github.com/php-amqp/php-amqp/tree/latest/stubs
- Symfony Config component: https://symfony.com/doc/current/components/config/definition.html
