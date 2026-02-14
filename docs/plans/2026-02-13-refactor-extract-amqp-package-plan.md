---
title: "refactor: Extract contracts + AMQP into separate packages"
type: refactor
date: 2026-02-13
brainstorm: docs/brainstorms/2026-02-12-transport-agnostic-architecture-brainstorm.md
prior_plan: docs/plans/2026-02-13-refactor-transport-agnostic-architecture-plan.md
revision: 3 (contracts namespace rename completed, reviewer feedback incorporated)
---

# refactor: Extract contracts + AMQP into separate packages

## Overview

Split `freyr/message-broker` into three packages:

```
        freyr/message-broker-contracts
        (interfaces, stamps, attributes)
               ^              ^
               |              |
  freyr/message-broker    freyr/message-broker-amqp
  (core: inbox, outbox,   (publisher, routing,
   serializers, dedup)      topology, commands)
               ^              ^
               +-- User App --+
```

Both core and AMQP depend on contracts, **not on each other**. The user's application requires both packages; the compiler pass in core auto-discovers tagged `OutboxPublisherInterface` implementations from any installed transport package.

## Why Three Packages

1. **AMQP must not depend on core** -- a transport plugin should only need the contracts to implement `OutboxPublisherInterface`
2. **Future transports (SQS, Kafka) follow the same pattern** -- they depend on contracts only
3. **Applications not using AMQP** do not pull in `ext-amqp` or `symfony/amqp-messenger`
4. **Independent versioning** -- transport plugins evolve separately from core

## Key Design Decision: Dedicated Contracts Namespace

Contracts live under `Freyr\MessageBroker\Contracts\` -- a dedicated namespace that cleanly separates shared interfaces from implementation code. This rename has already been completed in the current codebase.

| Package | PSR-4 Prefix | PSR-4 Root |
|---|---|---|
| `freyr/message-broker-contracts` | `Freyr\MessageBroker\Contracts\` | `src/` |
| `freyr/message-broker` | `Freyr\MessageBroker\` | `src/` |
| `freyr/message-broker-amqp` | `Freyr\MessageBroker\Amqp\` | `src/` |

No PSR-4 prefix overlap between packages. Each package owns its namespace exclusively.

**Status:** The namespace rename from `Freyr\MessageBroker\{Outbox,Stamp,Attribute,Inbox}\*` to `Freyr\MessageBroker\Contracts\*` is complete. All 7 contract classes now live in `src/Contracts/` with updated imports across the entire codebase. PHPStan, ECS, and all tests pass.

## Package Contents

### `freyr/message-broker-contracts` (7 files)

Thin package -- interfaces, stamps, attributes, and the marker interface.

| File | Purpose |
|---|---|
| `src/OutboxPublisherInterface.php` | Transport publisher contract |
| `src/OutboxMessage.php` | Marker interface for outbox events |
| `src/MessageName.php` | `#[MessageName]` attribute |
| `src/MessageIdStamp.php` | Message ID stamp (deduplication) |
| `src/MessageNameStamp.php` | Message name stamp (routing) |
| `src/ResolvesFromClass.php` | Cached attribute resolution trait |
| `src/DeduplicationStore.php` | Deduplication store interface |

**Dependencies:** `symfony/messenger`, `freyr/identity`

### `freyr/message-broker` (core, after extraction)

Everything remaining after contracts and AMQP are extracted.

**Source files retained:**
- `src/Outbox/MessageIdStampMiddleware.php`
- `src/Outbox/MessageNameStampMiddleware.php`
- `src/Outbox/OutboxPublishingMiddleware.php`
- `src/Inbox/DeduplicationMiddleware.php`
- `src/Inbox/DeduplicationDbalStore.php`
- `src/Serializer/InboxSerializer.php`
- `src/Serializer/WireFormatSerializer.php`
- `src/Serializer/Normalizer/IdNormalizer.php`
- `src/Serializer/Normalizer/CarbonImmutableNormalizer.php`
- `src/Doctrine/Type/IdType.php`
- `src/Command/DeduplicationStoreCleanup.php`
- `src/DependencyInjection/*` (extension, configuration, compiler pass)
- `src/FreyrMessageBrokerBundle.php`
- `config/services.yaml` (AMQP lines removed)

**Deleted from core:**
- `src/Amqp/` (entire directory)
- AMQP services from `config/services.yaml` (lines 72-115)
- `addAmqpSection()` from `Configuration.php`
- AMQP parameter setting + `validateBindingReferences()` from `FreyrMessageBrokerExtension.php`

**Dependencies:** `freyr/message-broker-contracts`, `symfony/*`, `doctrine/dbal`, `doctrine/orm`, `freyr/identity`, `nesbot/carbon`

### `freyr/message-broker-amqp` (9 source files + DI)

| Current Location | New Package File |
|---|---|
| `src/Amqp/AmqpOutboxPublisher.php` | `src/AmqpOutboxPublisher.php` |
| `src/Amqp/AmqpConnectionFactory.php` | `src/AmqpConnectionFactory.php` |
| `src/Amqp/TopologyManager.php` | `src/TopologyManager.php` |
| `src/Amqp/DefinitionsFormatter.php` | `src/DefinitionsFormatter.php` |
| `src/Amqp/Command/SetupAmqpTopologyCommand.php` | `src/Command/SetupAmqpTopologyCommand.php` |
| `src/Amqp/Routing/AmqpRoutingStrategyInterface.php` | `src/Routing/AmqpRoutingStrategyInterface.php` |
| `src/Amqp/Routing/DefaultAmqpRoutingStrategy.php` | `src/Routing/DefaultAmqpRoutingStrategy.php` |
| `src/Amqp/Routing/AmqpExchange.php` | `src/Routing/AmqpExchange.php` |
| `src/Amqp/Routing/AmqpRoutingKey.php` | `src/Routing/AmqpRoutingKey.php` |
| (new) | `src/FreyrMessageBrokerAmqpBundle.php` |
| (new) | `src/DependencyInjection/Configuration.php` |
| (new) | `src/DependencyInjection/FreyrMessageBrokerAmqpExtension.php` |
| (new) | `config/services.yaml` |

**Dependencies:** `freyr/message-broker-contracts`, `ext-amqp`, `symfony/amqp-messenger`, `symfony/messenger`, `symfony/console`, `symfony/config`, `symfony/dependency-injection`, `symfony/http-kernel`

**Does NOT depend on:** `freyr/message-broker`

### `composer.json` for contracts

```json
{
    "name": "freyr/message-broker-contracts",
    "description": "Shared contracts for Freyr Message Broker — interfaces, stamps, and attributes",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.2",
        "symfony/messenger": "^6.4|^7.0",
        "freyr/identity": "^0.4"
    },
    "autoload": {
        "psr-4": {
            "Freyr\\MessageBroker\\Contracts\\": "src/"
        }
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

### `composer.json` for AMQP

```json
{
    "name": "freyr/message-broker-amqp",
    "description": "AMQP transport plugin for Freyr Message Broker — RabbitMQ publishing, routing, and topology management",
    "type": "symfony-bundle",
    "license": "MIT",
    "require": {
        "php": ">=8.2",
        "freyr/message-broker-contracts": "^0.1",
        "ext-amqp": "*",
        "symfony/amqp-messenger": "^6.4|^7.0",
        "symfony/messenger": "^6.4|^7.0",
        "symfony/console": "^6.4|^7.0",
        "symfony/config": "^6.4|^7.0",
        "symfony/dependency-injection": "^6.4|^7.0",
        "symfony/http-kernel": "^6.4|^7.0"
    },
    "require-dev": {
        "phpstan/phpstan": "^2.1",
        "symplify/easy-coding-standard": "^13.0",
        "phpunit/phpunit": "^11.0|^12.0",
        "freyr/identity": "^0.4",
        "nesbot/carbon": "^2.0|^3.0"
    },
    "autoload": {
        "psr-4": {
            "Freyr\\MessageBroker\\Amqp\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Freyr\\MessageBroker\\Amqp\\Tests\\": "tests/"
        }
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

### AMQP DI Configuration

#### `FreyrMessageBrokerAmqpBundle`

```php
namespace Freyr\MessageBroker\Amqp;

use Freyr\MessageBroker\Amqp\DependencyInjection\FreyrMessageBrokerAmqpExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class FreyrMessageBrokerAmqpBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return dirname(__DIR__);
    }

    public function getContainerExtension(): ExtensionInterface
    {
        return new FreyrMessageBrokerAmqpExtension();
    }
}
```

#### Configuration

The AMQP config tree moves from `message_broker.amqp.*` to `message_broker_amqp.*`:

```yaml
# Before (under core):
message_broker:
    amqp:
        routing: ...
        topology: ...

# After (own extension):
message_broker_amqp:
    routing: ...
    topology: ...
```

#### `config/services.yaml`

```yaml
services:
    _defaults:
        autowire: false
        autoconfigure: false
        public: false

    Freyr\MessageBroker\Amqp\Routing\AmqpRoutingStrategyInterface:
        class: Freyr\MessageBroker\Amqp\Routing\DefaultAmqpRoutingStrategy
        arguments:
            $defaultSenderName: 'amqp'
            $routingOverrides: '%message_broker_amqp.routing_overrides%'

    Freyr\MessageBroker\Amqp\AmqpOutboxPublisher:
        arguments:
            $senderLocator: !service_locator
                amqp: '@messenger.transport.amqp'
            $routingStrategy: '@Freyr\MessageBroker\Amqp\Routing\AmqpRoutingStrategyInterface'
            $logger: '@logger'
        tags:
            - { name: 'message_broker.outbox_publisher', transport: 'outbox' }

    Freyr\MessageBroker\Amqp\TopologyManager:
        arguments:
            $topology: '%message_broker_amqp.topology%'
            $logger: '@logger'

    Freyr\MessageBroker\Amqp\DefinitionsFormatter:
        arguments:
            $topology: '%message_broker_amqp.topology%'

    Freyr\MessageBroker\Amqp\AmqpConnectionFactory: ~

    Freyr\MessageBroker\Amqp\Command\SetupAmqpTopologyCommand:
        arguments:
            $topologyManager: '@Freyr\MessageBroker\Amqp\TopologyManager'
            $definitionsFormatter: '@Freyr\MessageBroker\Amqp\DefinitionsFormatter'
            $connectionFactory: '@Freyr\MessageBroker\Amqp\AmqpConnectionFactory'
            $defaultDsn: '%env(default::MESSENGER_AMQP_DSN)%'
        tags: ['console.command']
```

## Reviewer Findings Addressed

### CRITICAL: `EventBusFactory` has direct AMQP imports (Kieran #3)

`tests/Unit/Factory/EventBusFactory.php` imports `AmqpOutboxPublisher` and `DefaultAmqpRoutingStrategy` in `createForInboxFlowTesting()`. After extraction, core tests cannot depend on AMQP.

**Resolution:** Create `tests/Unit/Store/InMemoryOutboxPublisher.php` in core that implements `OutboxPublisherInterface`. It stores published envelopes in an array. Replace the `AmqpOutboxPublisher` usage in `EventBusFactory` with this stub.

### CRITICAL: `ConfigurationTest` not in test migration plan (Kieran #2)

`tests/Unit/DependencyInjection/ConfigurationTest.php` (282 lines) tests AMQP topology configuration exclusively.

**Resolution:** Migrate this test to the AMQP package and update it to use `message_broker_amqp` root node. Remove AMQP-specific assertions from any remaining core configuration test.

### CRITICAL: `AmqpTestMessage` used by core serialisation tests (Kieran #4)

`tests/Unit/TransportSerializerTest.php` and `tests/Unit/OutboxSerializationTest.php` import `AmqpTestMessage`, but it only uses `#[MessageName]` and `OutboxMessage` (both from contracts). It has no AMQP-specific attributes.

**Resolution:** Rename to `SampleOutboxMessage` and keep in core test fixtures. No AMQP dependency exists.

### HIGH: Functional test config references AMQP services (Kieran #5)

`tests/Functional/config/test.yaml` contains AMQP service definitions and the `message_broker.amqp` config block. `SetupAmqpTopologyCommandTest` is a functional test.

**Resolution:**
- Remove AMQP service definitions and config from `tests/Functional/config/test.yaml`
- Migrate `SetupAmqpTopologyCommandTest` to the AMQP package
- Verify remaining functional tests pass

### HIGH: Sender locator hardcodes single `amqp` key (DHH, Kieran #6)

Pre-existing issue: the sender locator only has `amqp` key but routing strategy can return different sender names.

**Resolution:** Not addressed in this extraction (pre-existing). Document as a known limitation. The `FreyrMessageBrokerAmqpExtension` should eventually build the sender locator dynamically from routing configuration.

### MEDIUM: `php-amqplib` in `require-dev` with no usage (DHH, Kieran)

No source file in AMQP code imports from `php-amqplib`.

**Resolution:** Remove from both core and AMQP `require-dev`. If needed for future tests, add it back with justification.

### MEDIUM: `normaliseArguments` duplicated (Kieran #8)

Both `TopologyManager` and `DefinitionsFormatter` have identical `normaliseArguments()` methods.

**Resolution:** Extract to a shared `AmqpArgumentNormaliser` utility class within the AMQP package during the move.

## Implementation Phases

### Phase 0: Namespace Rename (COMPLETED)

The 7 contract classes have been renamed from their original namespaces to `Freyr\MessageBroker\Contracts\*`:

- [x] `Freyr\MessageBroker\Outbox\OutboxPublisherInterface` -> `Freyr\MessageBroker\Contracts\OutboxPublisherInterface`
- [x] `Freyr\MessageBroker\Outbox\OutboxMessage` -> `Freyr\MessageBroker\Contracts\OutboxMessage`
- [x] `Freyr\MessageBroker\Outbox\MessageName` -> `Freyr\MessageBroker\Contracts\MessageName`
- [x] `Freyr\MessageBroker\Stamp\MessageIdStamp` -> `Freyr\MessageBroker\Contracts\MessageIdStamp`
- [x] `Freyr\MessageBroker\Stamp\MessageNameStamp` -> `Freyr\MessageBroker\Contracts\MessageNameStamp`
- [x] `Freyr\MessageBroker\Attribute\ResolvesFromClass` -> `Freyr\MessageBroker\Contracts\ResolvesFromClass`
- [x] `Freyr\MessageBroker\Inbox\DeduplicationStore` -> `Freyr\MessageBroker\Contracts\DeduplicationStore`
- [x] All imports updated across source, tests, and YAML configs
- [x] `X-Message-Stamp-*` header keys updated in test files
- [x] PHPStan, ECS, unit tests, functional tests all pass
- [x] Empty `src/Stamp/` and `src/Attribute/` directories removed

### Phase 1: Create `freyr/message-broker-contracts` (COMPLETED)

- [x] Create `../message-broker-contracts/` directory
- [x] Create `composer.json` (as specified above)
- [x] Create `.gitignore`, `LICENSE` (MIT)
- [x] Copy 7 contract files from core `src/Contracts/` to contracts package `src/` (flatten — remove `Contracts/` directory level)
- [x] Initialise git repository
- [x] Run `composer install` (via Docker)
- [x] Verify autoloading resolves all 7 classes

### Phase 2: Create `freyr/message-broker-amqp` (COMPLETED)

- [x] Create `../message-broker-amqp/` directory
- [x] Create `composer.json` (with path repository pointing to contracts for dev, `@dev` constraint)
- [x] Create `.gitignore`, `LICENSE`, `phpunit.xml.dist`, `phpstan.dist.neon`, `ecs.php`, `tests/bootstrap.php`
- [x] Copy 9 source files from core `src/Amqp/` to AMQP `src/` (drop the `Amqp/` directory level)
- [x] Create `FreyrMessageBrokerAmqpBundle`, `DependencyInjection/Configuration.php`, `DependencyInjection/FreyrMessageBrokerAmqpExtension.php`
- [x] Create `config/services.yaml`
- [x] Copy and adapt tests:
  - [x] `tests/Unit/Amqp/*` tests (5 files — namespace `Amqp\Tests\Unit\*`)
  - [x] `tests/Unit/DependencyInjection/ConfigurationTest.php` (adapted to `message_broker_amqp` root node)
  - [x] `tests/Unit/Fixtures/CommerceTestMessage.php` + `TestMessage.php`
  - [ ] `tests/Functional/Command/SetupAmqpTopologyCommandTest.php` (deferred — needs kernel + RabbitMQ)
- [x] Extract `AmqpArgumentNormaliser` utility (deduplicates `normaliseArguments()`)
- [x] Initialise git repository
- [x] Run `composer install`, PHPStan (0 errors), ECS (clean), PHPUnit (51 tests, 135 assertions)

### Phase 3: Clean up core `freyr/message-broker` (COMPLETED)

- [x] Add `freyr/message-broker-contracts` as a Composer `require` dependency
- [x] Remove contract files from core `src/Contracts/` (they now live in contracts package)
- [x] Delete `src/Amqp/` directory entirely
- [x] Remove AMQP services from `config/services.yaml`
- [x] Remove `addAmqpSection()` from `Configuration.php`
- [x] Remove AMQP parameters + `validateBindingReferences()` from `FreyrMessageBrokerExtension.php`
- [x] Move `ext-amqp`, `symfony/amqp-messenger`, `php-amqplib` from `require` to `require-dev` only (functional tests still need them); remove `suggest` section
- [x] Fix `EventBusFactory`: create `InMemoryOutboxPublisher` test stub, replace AMQP imports
- [x] Rename `AmqpTestMessage` to `SampleOutboxMessage` in core test fixtures, update imports
- [x] Remove AMQP service definitions from `tests/Functional/config/test.yaml`; create `TestOutboxPublisher` fixture for functional tests
- [x] Delete `tests/Unit/Amqp/` directory
- [x] Delete `tests/Unit/Fixtures/CommerceTestMessage.php`
- [x] Delete `tests/Functional/Command/SetupAmqpTopologyCommandTest.php`
- [x] Rewrite `tests/Unit/DependencyInjection/ConfigurationTest.php` (inbox-only config)
- [x] Run full core test suite -- 68 tests, 266 assertions, all passing
- [x] Run PHPStan on core -- 0 errors
- [x] Run ECS on core -- clean

## Acceptance Criteria

- [ ] Contracts package installs standalone with no errors
- [ ] AMQP package installs with only contracts as MB dependency (not core)
- [ ] Core package installs with contracts and all tests pass
- [ ] User app requiring core + AMQP: compiler pass discovers `AmqpOutboxPublisher` via tag
- [ ] PHPStan level max passes on all three packages
- [ ] ECS passes on all three packages
- [ ] No references to `freyr/message-broker` in AMQP package's `require`
- [ ] Config key migration: `message_broker.amqp.*` -> `message_broker_amqp.*`

## Risk Analysis

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| PSR-4 autoload conflict (contracts + core sharing prefix) | Low | High | Composer handles merged prefixes; files partition cleanly with no overlap |
| `EventBusFactory` breaks after AMQP removal | **Certain** | Medium | Phase 3 creates `InMemoryOutboxPublisher` stub before deletion |
| `ConfigurationTest` breaks after AMQP removal | **Certain** | Medium | Phase 2 migrates test to AMQP package |
| Functional tests reference AMQP services | High | Medium | Phase 3 explicitly cleans `test.yaml` |
| Missing import after file move | Medium | Low | PHPStan at level max catches all undefined references |
| Config key change breaks consumers | Medium | Low | v0.x package, document in changelog |
