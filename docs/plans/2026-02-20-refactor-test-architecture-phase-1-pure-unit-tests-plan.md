---
title: "refactor: Test architecture restructure — Phase 1: Pure unit tests"
type: refactor
date: 2026-02-20
deepened: 2026-02-20
brainstorm: docs/brainstorms/2026-02-19-test-architecture-restructure-brainstorm.md
---

# refactor: Test architecture restructure — Phase 1: Pure unit tests

## Enhancement Summary

**Deepened on:** 2026-02-20
**Research agents used:** PHPUnit 11 best practices, Symfony Messenger testing patterns, framework docs, TDD review, architecture strategist, pattern recognition, code simplicity

### Key Improvements

1. **PHPUnit 11 configuration hardened** — `failOnDeprecation`, `executionOrder`, `#[CoversClass]` attributes, schema 11.5
2. **SerializerFactory dropped** — inline 12-line construction in each serialiser test's `setUp()` (only 2 consumers, not worth the indirection)
3. **Pattern normalisation** — consistent naming, setUp vs factory rules, assertion messages, import conventions
4. **Tiered execution order** — normalizers before serialisers, verify per-tier before proceeding
5. **"Break the code" verification** — for new test classes, deliberately break source to confirm test catches it

### Research Insights Applied

- **TDD skill review**: This is a characterisation testing exercise (test-only change, no production code). TDD Red-Green-Refactor does not apply. Write tests for existing code, verify they pass, use "break the code" for confidence.
- **Architecture review**: Mirrored directory structure is correct. Factories over traits. The 12-class scope covers all pure-unit-testable source files.
- **Pattern analysis**: Existing tests have strong, consistent patterns. Two minor inconsistencies to normalise (FQCN imports in ConfigurationTest, assertions inside mock callbacks in SetupDeduplicationCommandTest).
- **Simplicity review**: MiddlewareStackFactory justified (4 consumers, tricky by-reference logic). SerializerFactory not justified (2 consumers, 12 lines each).

---

## Overview

Delete the entire existing test suite and rebuild with pure behaviour-focused unit tests. No infrastructure dependencies. Later phases will add integration and functional tests.

## Problem Statement

The current test suite (~35 files, 78 test methods) has two problems:
- "Unit" tests create full `MessageBus` instances via `EventBusFactory`, middleware stacks, in-memory transports — ~577 lines of test infrastructure
- Functional tests require Docker (MySQL + RabbitMQ), overlap with unit tests, slow feedback

This phase replaces everything with ~59 pure unit tests across 12 classes.

## Proposed Solution

Delete all tests. Create `tests/Unit/` mirroring the `src/` namespace structure. Each test uses real `Envelope`/`Stamp` objects and mocks for `StackInterface`, `Connection`, etc. Serialiser tests use a real Symfony Serializer (pure object, no I/O).

## Technical Approach

### Existing Pure Unit Tests (move, don't rewrite)

Seven existing test classes are already in the target style:
- `OutboxPublishingMiddlewareTest.php` (8 methods)
- `MessageIdStampMiddlewareTest.php` (4 methods)
- `MessageNameStampMiddlewareTest.php` (5 methods)
- `WireFormatSerializerTest.php` (8 methods)
- `SetupDeduplicationCommandTest.php` (5 methods)
- `ConfigurationTest.php` (7 methods)
- `OutboxPublisherPassTest.php` (6 methods)

Move these to the new structure with namespace and fixture adjustments. Add missing scenarios where noted.

**Move strategy**: Copy-paste-adjust, not rewrite from scratch. The existing test logic and assertions are correct — only namespaces, fixture references, and imports change. After adjusting each class, compare against the scenario list to confirm coverage.

### Fixtures

```
tests/Fixtures/
    TestOutboxEvent.php   # OutboxMessage + #[MessageName('test.event.sent')] + Id, string, CarbonImmutable
    TestInboxEvent.php    # Plain readonly class (no OutboxMessage, no #[MessageName]) — inbox-side
    TestPublisher.php     # Stub OutboxPublisherInterface — needed by OutboxPublisherPassTest
```

Error-path tests use inline anonymous classes where needed.

**Fixture conventions** (from pattern analysis):
- All fixtures are `final readonly class`
- `TestOutboxEvent` mirrors `TestMessage` but with clearer naming. Consider adding `random()` static factory to reduce `new TestOutboxEvent(id: Id::new(), payload: 'Test', occurredAt: CarbonImmutable::now())` boilerplate
- `TestInboxEvent` deliberately omits `OutboxMessage` and `#[MessageName]` — this reflects the real architectural boundary between publisher and consumer
- `TestPublisher` is used by `OutboxPublisherPassTest` (compiler pass needs a concrete class, not anonymous). Mark as `final readonly class` for consistency

### Shared Helpers

- **`MiddlewareStackFactory`** — existing 58-line helper, provides `createTracking()` and `createPassThrough()`. Used by all 4 middleware tests. **Justified**: the by-reference tracking pattern (`$nextCalled`) is tricky and error-prone to duplicate. Keep as-is.

**Dropped: `SerializerFactory`** — the Symfony Serializer construction is 12 lines. With only 2 consumers (`WireFormatSerializerTest` and `InboxSerializerTest`), inlining in each test's `setUp()` is simpler than a shared factory. Each test constructs its own serialiser directly.

## Target Directory Structure

```
tests/
├── bootstrap.php
├── Fixtures/
│   ├── TestOutboxEvent.php
│   ├── TestInboxEvent.php
│   └── TestPublisher.php
└── Unit/
    ├── MiddlewareStackFactory.php
    ├── Outbox/
    │   ├── MessageIdStampMiddlewareTest.php
    │   ├── MessageNameStampMiddlewareTest.php
    │   └── OutboxPublishingMiddlewareTest.php
    ├── Inbox/
    │   └── DeduplicationMiddlewareTest.php
    ├── Serializer/
    │   ├── WireFormatSerializerTest.php
    │   ├── InboxSerializerTest.php
    │   └── NormalizerTest.php
    ├── Command/
    │   ├── SetupDeduplicationCommandTest.php
    │   └── DeduplicationStoreCleanupTest.php
    ├── DependencyInjection/
    │   ├── ConfigurationTest.php
    │   ├── FreyrMessageBrokerExtensionTest.php
    │   └── Compiler/
    │       └── OutboxPublisherPassTest.php
    └── Doctrine/
        └── IdTypeTest.php
```

## Test Conventions (from pattern analysis)

These conventions were extracted from the 7 existing pure unit tests and should be followed by all 12 classes:

### File Structure
- `declare(strict_types=1);` immediately after `<?php`
- One `use` statement per class — never grouped imports, never inline FQCN
- `final class {Name}Test extends TestCase` — no `readonly` on test classes

### Class Docblocks
Every test class has a docblock enumerating what it tests:
```php
/**
 * Unit test for [Subject].
 *
 * Tests that the [subject]:
 * - [Behaviour 1]
 * - [Behaviour 2]
 */
```

### SUT Instantiation Rule
- **Identical construction for all tests** → use `setUp()` with a class property (e.g., `MessageIdStampMiddlewareTest`)
- **Variable construction per test** → use a `private function create[SutName](...): SutType` factory method (e.g., `OutboxPublishingMiddlewareTest::createMiddleware()`)

### Method Naming
Follow the dominant convention: `test[Subject][Verb][ExpectedBehaviour]` in camelCase:
```
testOutboxMessageGetsStampedWithMessageIdStamp
testNonOutboxMessagePassesThroughWithoutStamp
testDuplicateMessageIsNotProcessedTwice
```

### Assertions
- Use `assertSame()` for all scalar/string comparisons — never `assertEquals()` for scalars
- Provide assertion messages for non-obvious assertions: `$this->assertNotNull($stamp, 'OutboxMessage should receive MessageIdStamp')`
- Exception testing: `$this->expectException(ClassName::class)` + `$this->expectExceptionMessageMatches('/pattern/')`

### PHPUnit 11 Attributes
- Add `#[CoversClass(TargetClass::class)]` to each test class for strict coverage enforcement
- Data providers must be `public static` methods
- Use `#[DataProvider('methodName')]` attribute, not `@dataProvider` annotation

## PHPUnit Configuration

Update `phpunit.xml.dist` with PHPUnit 11 best practices:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/11.5/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         failOnRisky="true"
         failOnWarning="true"
         failOnDeprecation="true"
         beStrictAboutCoverageMetadata="true"
         cacheDirectory=".phpunit.cache"
         executionOrder="depends,defects">

    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>

    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>

    <php>
        <env name="APP_ENV" value="test"/>
    </php>
</phpunit>
```

**Changes from current config:**
- Schema updated from 11.0 to 11.5
- Added `failOnDeprecation="true"` — surfaces PHPUnit deprecations early
- Added `beStrictAboutCoverageMetadata="true"` — requires `#[CoversClass]` on tests
- Added `executionOrder="depends,defects"` — runs failed tests first for faster feedback
- Removed `DATABASE_URL` and `MESSENGER_AMQP_DSN` env vars — no infrastructure needed
- Removed `Functional` test suite — only `Unit` remains

## Serialiser Construction Pattern

Both `WireFormatSerializerTest` and `InboxSerializerTest` need a real Symfony Serializer. Inline this in each test's `setUp()`:

```php
protected function setUp(): void
{
    $reflectionExtractor = new ReflectionExtractor();
    $propertyTypeExtractor = new PropertyInfoExtractor(
        [$reflectionExtractor],
        [$reflectionExtractor],
        [],
        [$reflectionExtractor],
        [$reflectionExtractor]
    );

    $symfonySerializer = new Serializer(
        [
            new IdNormalizer(),
            new CarbonImmutableNormalizer(),
            new ArrayDenormalizer(),
            new ObjectNormalizer(null, null, null, $propertyTypeExtractor),
        ],
        [new JsonEncoder()]
    );

    $this->serializer = new WireFormatSerializer($symfonySerializer);
    // or: $this->serializer = new InboxSerializer($symfonySerializer, $messageTypes);
}
```

**Critical details** (from framework docs research):
- Normalizer ordering matters: custom normalizers (`IdNormalizer`, `CarbonImmutableNormalizer`) must precede `ObjectNormalizer`
- `ArrayDenormalizer` is required for stamp deserialization (stamps are encoded as JSON arrays `[{"messageId":"..."}]`)
- `ObjectNormalizer` needs `PropertyInfoExtractor` with `ReflectionExtractor` for constructor property promotion support
- The same serializer instance serves both message body and stamp serialization

## Implementation Phases

### Execution Strategy

**Delete first, then rebuild.** This is an unreleased package — no regression risk from a clean slate.

**Write and verify per tier.** After completing each phase, run:
```bash
docker compose run --rm php vendor/bin/phpunit --testsuite Unit
```
Do not cross to the next phase until all tests in the current phase are green.

**"Break the code" verification for new tests.** For each of the 5 new test classes (not moved), after the test passes, deliberately break the source code and confirm the test catches it. This validates the test has genuine diagnostic power.

### Phase 1: Setup

- [x] Delete `tests/Functional/` and `tests/Unit/` directories
- [x] Create directory structure matching target above
- [x] Update `phpunit.xml.dist` — single `Unit` test suite, PHPUnit 12 best practices config
- [x] Create fixtures: `TestOutboxEvent.php`, `TestInboxEvent.php`, `TestPublisher.php`
- [x] Move `MiddlewareStackFactory.php` to `tests/Unit/` (adjust namespace to `Freyr\MessageBroker\Tests\Unit`)
- [x] Run `docker compose run --rm php vendor/bin/phpunit` — verify empty suite passes

### Phase 2: Middleware Tests (move 3, create 1)

**Tier order follows the middleware chain dependency depth:**

**Move and adapt:**

- [x] `MessageIdStampMiddlewareTest` → `tests/Unit/Outbox/`. **4 scenarios:**
  1. Non-OutboxMessage passes through unchanged
  2. OutboxMessage with ReceivedStamp passes through unchanged
  3. OutboxMessage without MessageIdStamp gets stamped with UUID v7
  4. OutboxMessage with existing MessageIdStamp is not re-stamped

- [x] `MessageNameStampMiddlewareTest` → `tests/Unit/Outbox/`. **5 scenarios:**
  1. Non-OutboxMessage passes through unchanged
  2. OutboxMessage with ReceivedStamp passes through unchanged
  3. OutboxMessage with `#[MessageName]` gets MessageNameStamp added
  4. OutboxMessage already having MessageNameStamp is not re-stamped
  5. OutboxMessage without `#[MessageName]` attribute throws RuntimeException

- [x] `OutboxPublishingMiddlewareTest` → `tests/Unit/Outbox/`. **8 scenarios:**
  1. Non-OutboxMessage passes through to next middleware
  2. OutboxMessage without ReceivedStamp passes through (dispatch path)
  3. Consumed from transport without registered publisher logs at debug and passes through
  4. Missing MessageNameStamp throws RuntimeException (checked first in source)
  5. Missing MessageIdStamp throws RuntimeException (envelope must include MessageNameStamp)
  6. Outbox-consumed message delegates to resolved publisher
  7. Publisher receives clean envelope (OutboxMessage + stamps only)
  8. Short-circuits after publishing — returns original envelope (identity check)

**Create new:**

- [x] `DeduplicationMiddlewareTest` → `tests/Unit/Inbox/`. Mock `DeduplicationStore`. **5 scenarios:**
  1. Message without ReceivedStamp passes through (dispatch path)
  2. Received message without MessageIdStamp passes through
  3. New message (store returns false) — calls next middleware, propagates its return value
  4. Duplicate message (store returns true) — short-circuits, returns envelope without calling next
  5. Store receives message FQN as `messageName` argument (not stamp value)

**Middleware testing pattern** (from Symfony Messenger research):
- Use `MiddlewareStackFactory::createTracking($nextCalled)` to verify next-was-called semantics — this creates a real `StackMiddleware` with a tracking anonymous class, more realistic than mocking `StackInterface` directly
- Use `MiddlewareStackFactory::createPassThrough()` when you only need the return value
- For `DeduplicationMiddleware`, mock `DeduplicationStore` interface (the DBAL implementation requires infrastructure — deferred to Phase 2 integration tests)
- Every middleware test should verify stamp presence/absence via `$envelope->last(StampClass::class)` and `$envelope->all(StampClass::class)`

- [x] Run tests — verify green

### Phase 3: Serialiser Tests (move 1, create 2)

NormalizerTest first, then serialiser tests (normalizer failures would cause confusing serialiser errors).

- [x] `NormalizerTest` → `tests/Unit/Serializer/`. Both normalizers, direct invocation (no shared factory). **2 scenarios:**
  1. IdNormalizer: round-trip normalize→denormalize preserves Id value
  2. CarbonImmutableNormalizer: round-trip normalize→denormalize preserves timestamp

- [x] `WireFormatSerializerTest` — move from `tests/Unit/Serializer/`, adapt fixtures. Add scenario 6. **7 scenarios:**
  1. encode(): Extracts semantic name from MessageNameStamp, sets `type` header
  2. encode(): Sets `X-Message-Class` header with original FQN
  3. encode(): Missing MessageNameStamp throws RuntimeException
  4. encode(): Body contains only business data (no messageId)
  5. decode(): Restores FQN from `X-Message-Class` header, replaces semantic `type`, removes `X-Message-Class` from output
  6. decode(): When `type` header already contains backslash (FQN), skips replacement (retry path)
  7. Round-trip: encode→decode preserves message integrity, stamps, and business data

  **WireFormatSerializer decode detail** (from source analysis): Line 81 has a 3-clause guard: `is_string($semanticName) && is_string($fqn) && !str_contains($semanticName, '\\')`. Scenario 6 tests the FQN-passthrough path where `type` already contains a backslash — this is the retry/failed message path where messages return through the Doctrine transport serialiser.

- [x] `InboxSerializerTest` → `tests/Unit/Serializer/`. New file. **10 scenarios:**
  1. decode(): Translates semantic `type` header to PHP FQN via message_types mapping
  2. decode(): Missing `type` header throws MessageDecodingFailedException
  3. decode(): Non-array headers throws MessageDecodingFailedException
  4. decode(): Unknown message type throws MessageDecodingFailedException with config guidance
  5. decode(): FQN in `type` header (not in mapping) throws MessageDecodingFailedException
  6. decode(): Adds MessageNameStamp with semantic name if not already present
  7. decode(): Preserves existing MessageNameStamp from `X-Message-Stamp-*` headers (retry path)
  8. encode(): Uses MessageNameStamp to set semantic `type` header (retry/failed path)
  9. encode(): Without MessageNameStamp preserves FQN in `type` header
  10. Round-trip: decode→encode preserves semantic name for retry scenarios

  **InboxSerializer construction for testing:**
  ```php
  $this->serializer = new InboxSerializer($symfonySerializer, [
      'test.event.sent' => TestOutboxEvent::class,
      'test.inbox.received' => TestInboxEvent::class,
  ]);
  ```

**Serialiser testing patterns** (from research):
- Build the real `Symfony\Component\Serializer\Serializer` with the exact normalizer chain used in production — never mock the serializer
- Test round-trips (encode→decode or decode→encode) to verify data integrity across the boundary
- Test individual encode/decode to verify header structure and error handling
- The parent `Serializer::encode()` automatically strips `NonSendableStampInterface` stamps (e.g., `ReceivedStamp`) — this is Symfony's behaviour, not ours
- Stamps are serialised into `X-Message-Stamp-{FQN}` headers as JSON arrays — `ArrayDenormalizer` handles the `Type[]` format

- [x] Run tests — verify green

### Phase 4: Commands, DI, Doctrine (move 3, create 3)

**Move and adapt:**

- [x] `SetupDeduplicationCommandTest` → `tests/Unit/Command/`. Add missing scenarios. **8 scenarios:**
  1. Dry-run mode (default): outputs SQL without executing
  2. `--force` mode: creates table via mocked SchemaManager
  3. `--force` when table exists: returns SUCCESS with skip message
  4. `--migration` mode: generates migration file (clean up in tearDown)
  5. `--migration` with empty migration directories: error
  6. `--migration` when file already exists: returns FAILURE
  7. `--force` and `--migration` together: error (mutual exclusivity)
  8. Missing migrations config: error with guidance

  **Pattern fix** (from pattern analysis): The existing test has 14 assertions inside a `willReturnCallback` closure on a mock object. If `getCreateTableSQL` is never called, the assertions silently pass. Add a `$callbackInvoked` flag to verify the callback was actually executed.

- [x] `ConfigurationTest` → `tests/Unit/DependencyInjection/`. **7 scenarios:**
  1. Default config: empty message_types, default table name
  2. Custom message_types mapping accepted
  3. Custom table name accepted
  4. Empty table name rejected
  5. Invalid table name (SQL injection chars) rejected
  6. Table name starting with number rejected
  7. Table name with only valid chars accepted

  **Pattern fix** (from pattern analysis): Replace inline FQCN `\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class` with a `use` import statement.

- [x] `OutboxPublisherPassTest` → `tests/Unit/DependencyInjection/Compiler/`. **6 scenarios:**
  1. No tagged services: middleware gets empty locator
  2. Early return when OutboxPublishingMiddleware definition is absent
  3. Tagged service with valid transport: registered in locator
  4. Tagged service missing `transport` attribute: throws error
  5. Duplicate transport: throws error
  6. Tagged service not implementing OutboxPublisherInterface: throws error

  **Compiler pass testing pattern** (from research): Always use a real `ContainerBuilder` and real `Definition` objects — never mock these. The pass processes definitions at the definition level; no need to call `$container->compile()`.

  **Pattern improvement**: Extract a `private function createContainerWithMiddleware(): ContainerBuilder` helper within this test class to reduce the 4-line middleware definition boilerplate repeated in 5 of 6 tests.

**Create new:**

- [x] `DeduplicationStoreCleanupTest` → `tests/Unit/Command/`. Mock `Connection`. **3 scenarios:**
  1. Uses specified `--days` value in SQL parameter
  2. Non-numeric `--days=abc`: falls back to `30`
  3. Custom `tableName` flows into SQL statement

  **Command testing pattern**: Instantiate the command directly with mocked `Connection`, wrap in `CommandTester`. Assert both `getStatusCode()` and `getDisplay()`.

- [x] `FreyrMessageBrokerExtensionTest` → `tests/Unit/DependencyInjection/`. Uses `ContainerBuilder`. **2 scenarios:**
  1. Sets `message_broker.inbox.message_types` parameter from config
  2. Sets `message_broker.inbox.deduplication.table_name` parameter from config

  **Extension testing pattern**: Create a real `ContainerBuilder`, call `$extension->load([$config], $container)`, then assert parameters via `$container->getParameter()`.

- [x] `IdTypeTest` → `tests/Unit/Doctrine/`. **7 scenarios:**
  1. convertToPHPValue(): binary string → Id
  2. convertToPHPValue(): null → null
  3. convertToPHPValue(): Id instance → returns unchanged (early return)
  4. convertToPHPValue(): non-string, non-null, non-Id → throws InvalidArgumentException
  5. convertToDatabaseValue(): Id → binary string
  6. convertToDatabaseValue(): null → null
  7. convertToDatabaseValue(): non-Id, non-null → throws InvalidArgumentException

  **Doctrine type testing pattern**: Mock `AbstractPlatform` — it is not the thing under test. Types are stateless: no setup beyond instantiation. Always test null handling in both directions. Consider adding a round-trip test: `convertToPHPValue(convertToDatabaseValue($id))` preserves identity.

- [x] Run tests — verify green

### Phase 5: Final Verification

- [x] Verify no orphaned files remain: `find tests/ -name "*.php" | sort`
- [x] Run full suite: `docker compose run --rm php vendor/bin/phpunit --testdox`
- [x] Run PHPStan: `docker compose run --rm php vendor/bin/phpstan analyse`
- [x] Run ECS: `docker compose run --rm php vendor/bin/ecs check`
- [x] Update `.github/workflows/tests.yml` — remove MySQL and RabbitMQ services from test job

## Acceptance Criteria

- [x] All existing test files deleted
- [x] 12 test classes in `tests/Unit/` (mirroring `src/` namespace) + 3 fixtures + 1 helper
- [x] 74 test methods, all green (exceeded ~59 target)
- [x] Zero infrastructure dependencies
- [x] PHPStan and ECS pass
- [x] CI workflow updated
- [x] Each test class has `#[CoversClass]` attribute
- [x] `phpunit.xml.dist` uses PHPUnit 12.5 schema with `failOnDeprecation` enabled

## Risk Mitigations

| Risk | Severity | Mitigation |
|---|---|---|
| New tests pass vacuously (mock too aggressively) | Medium | "Break the code" verification for all 5 new test classes |
| Loss of integration/flow coverage | Medium | Accepted for Phase 1; Phase 2 will add integration tests with SQLite |
| Fixture naming confusion (TestOutboxEvent vs TestMessage) | Low | Clear docblocks on each fixture explaining its architectural role |

## References

- Brainstorm: `docs/brainstorms/2026-02-19-test-architecture-restructure-brainstorm.md`
- Critical patterns: `docs/solutions/patterns/critical-patterns.md`
- PHPUnit 11.5 Manual: https://docs.phpunit.de/en/11.5/
- Symfony Messenger MiddlewareTestCase: `vendor/symfony/messenger/Test/Middleware/MiddlewareTestCase.php`
- Symfony Transport Serializer: `vendor/symfony/messenger/Transport/Serialization/Serializer.php`
