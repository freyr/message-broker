# Freyr Message Broker — Architectural Review

**Date:** 2026-02-06
**Reviewers:** Architecture Strategist, Pattern Recognition Specialist, Security Sentinel, Code Simplicity Reviewer,
Performance Oracle
**Scope:** Full codebase analysis (src/, config/, tests/, recipe/)

---

## Executive Summary

The Freyr Message Broker is an architecturally sound Symfony bundle with a clean separation between Inbox and Outbox
patterns. The core design correctly leverages Symfony Messenger primitives, and the extension points (routing strategy,
deduplication store, serializers) are well-chosen. The codebase comprises ~650 LOC of production code across ~20 files —
an appropriate size for the problem domain.

**However**, the review identified **1 critical**, **6 high**, **9 medium**, and **8 low** severity findings across
architecture, code quality, security, simplicity, and performance concerns.

---

## Findings by Severity

### CRITICAL

#### 1. Recipe Configuration References Non-Existent Serializer Class

**Files:** `recipe/1.0/config/packages/messenger.yaml` (lines 24, 37)

The Symfony Flex recipe references `Freyr\MessageBroker\Serializer\MessageNameSerializer`, which does not exist. The
actual classes are `OutboxSerializer` and `InboxSerializer`. Any user installing via Flex would get an immediately
broken configuration.

---

### HIGH

#### 2. Duplicated `extractMessageName()` Reflection Logic

**Files:** `src/Outbox/EventBridge/OutboxToAmqpBridge.php:69-82`, `src/Serializer/OutboxSerializer.php:112-128`

The same reflection-based attribute extraction is duplicated verbatim in two files (differing only in parameter type
hint and error message). Additionally, `DefaultAmqpRoutingStrategy` duplicates the reflection pattern twice more for
`MessengerTransport` and `AmqpRoutingKey` attributes — creating `ReflectionClass` twice per message for the same object.

**Recommendation:** Extract a static method on `MessageName` itself (e.g.,
`MessageName::fromClass(object $event): string`) to serve as the single source of truth.

#### 3. Missing `final` on `DeduplicationMiddleware`

**File:** `src/Inbox/DeduplicationMiddleware.php:14`

Every other concrete class in the bundle is `final readonly`, but `DeduplicationMiddleware` is only `readonly`. This
breaks the established convention and creates an unnecessary extension point in a security-sensitive component.

#### 4. Missing `final` on `DeduplicationStoreCleanup`

**File:** `src/Command/DeduplicationStoreCleanup.php:15`

The only source class that is neither `final` nor `readonly`. While `readonly` is constrained by the `Command` parent
class, `final` should still be applied.

#### 5. Logger Not Injected into `DeduplicationMiddleware`

**Files:** `config/services.yaml:54-58`, `src/Inbox/DeduplicationMiddleware.php:18`

The middleware's constructor accepts `?LoggerInterface $logger = null`, and contains logging for invalid UUID
detection (lines 44-48), but the service definition does not inject `'@logger'`. This means UUID validation warnings are
silently discarded in production.

#### 6. Middleware Priority Relies on Implicit Ordering Contract

**File:** `config/services.yaml:58`

The architectural invariant that `DeduplicationMiddleware` (priority -10) must run AFTER `doctrine_transaction` (
priority 0) is maintained purely through numeric convention. The test configuration at
`tests/Functional/config/test.yaml:17` explicitly lists the middleware in the chain, creating a discrepancy between test
and production registration mechanisms.

---

### MEDIUM

#### 7. `MessageIdStamp` Namespace Placement

**Files:** `src/Inbox/MessageIdStamp.php`, `src/Outbox/EventBridge/OutboxToAmqpBridge.php:53`

The stamp lives in the `Inbox` namespace but is **created** by the `Outbox` module. This cross-cutting concern belongs
in a shared namespace (e.g., `Freyr\MessageBroker\Stamp\MessageIdStamp`), as it inverts the expected dependency
direction (Outbox importing from Inbox).

#### 8. `$messageName` Semantic Overloading

**Files:** `src/Inbox/DeduplicationMiddleware.php:54`, `src/Inbox/DeduplicationStore.php:22`

The parameter `$messageName` in `DeduplicationStore::isDuplicate()` actually receives the PHP class FQN (e.g.,
`App\Message\OrderPlaced`), not the semantic name (e.g., `order.placed`). This is confusing given the bundle's
consistent use of "message name" for semantic names elsewhere.

#### 9. `AmqpRoutingStrategyInterface` Parameter Asymmetry

**File:** `src/Outbox/Routing/AmqpRoutingStrategyInterface.php:12-18`

Three methods, three different signatures: `getTransport(object)`, `getRoutingKey(object, string)`,
`getHeaders(string)`. Uses `object` instead of `OutboxMessage` for type safety. A uniform signature would be more
consistent and flexible.

#### 10. Hard-Coded Table Name in Deduplication Store

**Files:** `src/Inbox/DeduplicationDbalStore.php:30`, `src/Command/DeduplicationStoreCleanup.php:33`

Table name `message_broker_deduplication` is hard-coded in two locations. For a distributable bundle, this should be
configurable via the bundle configuration tree.

#### 11. `date()` Without Timezone Awareness

**File:** `src/Inbox/DeduplicationDbalStore.php:33`

Uses `date('Y-m-d H:i:s')` which depends on the server's default timezone. In distributed systems, workers may have
different timezone configurations, leading to inconsistent timestamps that affect the cleanup command's retention logic.

#### 12. `assert()` Used for Runtime Validation in Serialisers

**Files:** `src/Serializer/InboxSerializer.php:50,58,106`, `src/Serializer/OutboxSerializer.php:64,106`

PHP `assert()` can be disabled entirely via `zend.assertions = -1` in production. For a library processing external
messages, proper validation with explicit exceptions should be used.

#### 13. Speculative AMQP Headers (YAGNI)

**File:** `src/Outbox/Routing/DefaultAmqpRoutingStrategy.php:59-69`

`getHeaders()` decomposes message names into `x-message-domain`, `x-message-subdomain`, `x-message-action` headers. No
consumer reads these — the `type` header already carries the full semantic name. The `'unknown'` fallbacks confirm this
is speculative.

#### 14. `php-amqplib/php-amqplib` in Production Dependencies

**File:** `composer.json:36`

This library is only used in test infrastructure (`FunctionalTestCase`). It should be in `require-dev`. Production
consumers interact with AMQP through `ext-amqp` via Symfony's transport.

#### 15. `composer.json` Version Constraint vs Documentation Mismatch

**File:** `composer.json:24`

Requires `"symfony/messenger": "^6.4|^7.0"` but CLAUDE.md states "Symfony Messenger 7.3+". The actual constraint is
broader than documented.

---

### LOW

#### 16. Inconsistent Variable Naming for Attribute Instances

Across files: `$messageNameAttribute` (Bridge), `$messageNameAttr` (Serialiser), `$exchangeAttr` (Strategy) — three
different suffix conventions.

#### 17. No Validation on Attribute Constructor Values

**Files:** `src/Outbox/MessageName.php:18`, `src/Outbox/Routing/AmqpRoutingKey.php:30`,
`src/Outbox/Routing/MessengerTransport.php:29`

None validate their input. An empty `#[MessageName('')]` passes silently.

#### 18. Missing `@return` PHPDoc on `getHeaders()` Interface Method

**File:** `src/Outbox/Routing/AmqpRoutingStrategyInterface.php:18`

Returns bare `array` without type documentation.

#### 19. `DeduplicationStoreCleanup` Tightly Coupled to DBAL

**File:** `src/Command/DeduplicationStoreCleanup.php:17-18`

Depends directly on `Connection` rather than `DeduplicationStore` interface. If someone implements Redis-based
deduplication, the cleanup command still targets the DBAL table.

#### 20. Dead Code in Test Infrastructure

~65 LOC of unused test helpers: `publishTestEvent()`, `publishOrderPlacedEvent()`, `assertMessageInFailedTransport()`,
`getAcknowledged()`, `getRejected()`, `hasProcessed()`, `clear()`.

#### 21. Debug Tests in the Suite

`InboxHeaderDebugTest.php` and `InboxSerializerDebugTest.php` appear to be diagnostic tests rather than regression
tests. Consider removing or converting to proper unit tests.

#### 22. Test Service Configuration Duplicates Bundle Configuration

`tests/Functional/config/test.yaml` re-declares all service definitions from `config/services.yaml`. Changes must be
made in both places.

#### 23. Log Key Says "exchange" but Value is Transport Name

**File:** `src/Outbox/EventBridge/OutboxToAmqpBridge.php:62`

`'exchange' => $transport` — misleading for operators reading logs.

---

## Security Assessment

### No Critical Security Vulnerabilities Found

**Positive findings:**

- SQL injection protection is solid: `DeduplicationDbalStore` uses parameterised queries via Doctrine DBAL's `insert()`.
  The `DeduplicationStoreCleanup` command uses parameterised `executeStatement()`.
- The `getTableRowCount()` in `FunctionalTestCase` uses an allowlist (`ALLOWED_TABLES` constant) — no injection vector.
- UUID validation is performed before database operations, preventing binary corruption.
- Error messages in `DeduplicationMiddleware` use generic text ("invalid UUID format") rather than leaking
  implementation details.
- Test infrastructure has production safety checks (database name must contain `_test`).

**Areas of note (Info severity):**

- `InboxSerializer::decode()` resolves message types from a configuration map — not from untrusted input. The FQN is
  looked up in `$this->messageTypes`, preventing arbitrary class instantiation.
- No hardcoded credentials in source code (all DSNs use environment variables).
- AMQP connections use plain text by default; TLS should be configured at the infrastructure level (this is an
  operational concern, not a code vulnerability).

---

## Performance Assessment

### Reflection Usage Per Message

**Impact:** Medium (for high-throughput scenarios)

`OutboxToAmqpBridge`, `OutboxSerializer`, and `DefaultAmqpRoutingStrategy` all create `ReflectionClass` instances on
every message. The routing strategy creates it **twice** per message (once in `getTransport()`, once in
`getRoutingKey()`). For a high-throughput system processing thousands of messages per second, this adds measurable
overhead.

**Recommendation:** Cache reflection results in a static map keyed by class name.

### Deduplication INSERT Pattern

**Impact:** Low (well-optimised)

The "try INSERT, catch UniqueConstraintViolationException" pattern is correct and performant. The binary UUID v7 primary
key ensures ordered writes. The index on `processed_at` supports efficient cleanup queries.

### Transaction Lock Duration

**Impact:** Low (acceptable trade-off)

The deduplication INSERT happens within the `doctrine_transaction` middleware's transaction scope. This means the
transaction lock extends to cover both the handler's business logic and the deduplication check. This is by design — it
provides atomicity — and the lock duration depends on handler execution time, not the middleware itself.

---

## Design Patterns Identified

| Pattern            | Location                                                      | Quality |
|--------------------|---------------------------------------------------------------|---------|
| Strategy           | `AmqpRoutingStrategyInterface` / `DefaultAmqpRoutingStrategy` | Good    |
| Bridge / Mediator  | `OutboxToAmqpBridge`                                          | Good    |
| Middleware Chain   | `DeduplicationMiddleware`                                     | Good    |
| Marker Interface   | `OutboxMessage`                                               | Good    |
| Decorator          | `InboxSerializer`, `OutboxSerializer` extending `Serializer`  | Good    |
| Repository / Store | `DeduplicationStore` / `DeduplicationDbalStore`               | Good    |

---

## Strengths

1. **Clean Inbox/Outbox separation** — properly isolated in separate namespaces with minimal cross-dependencies
2. **Correct Symfony Messenger integration** — uses `#[AsMessageHandler]`, `ReceivedStamp` checks, `TransportNamesStamp`
   routing, and native stamp serialisation
3. **Well-designed interfaces** — `DeduplicationStore` (1 method, atomic contract) and `OutboxMessage` (marker interface
   enabling generic handler)
4. **Split serialiser pattern is justified** — different encoding/decoding requirements for publishing vs consuming
   flows
5. **Robust test infrastructure** — covers transaction rollback, concurrent processing, deduplication edge cases, and
   malformed message handling
6. **Production safety** — safety checks in tests, parameterised SQL, generic error messages
7. **Proper bundle distribution structure** — Flex recipe, configuration tree, YAML services

---

## Priority Action Items

| #  | Severity | Action                                                                   | Effort  | Status   |
|----|----------|--------------------------------------------------------------------------|---------|----------|
| 1  | Critical | Fix recipe serialiser class references                                   | Small   | RESOLVED |
| 2  | High     | Extract shared `extractMessageName()` utility                            | Small   | RESOLVED |
| 3  | High     | Add `final` to `DeduplicationMiddleware` and `DeduplicationStoreCleanup` | Trivial | RESOLVED |
| 4  | High     | Inject `@logger` into `DeduplicationMiddleware` service definition       | Trivial | RESOLVED |
| 5  | High     | Document/harmonise middleware registration between bundle and tests      | Medium  | RESOLVED |
| 6  | Medium   | Move `MessageIdStamp` to shared namespace                                | Small   | DEFERRED |
| 7  | Medium   | Replace `assert()` with explicit exceptions in serialisers               | Small   | RESOLVED |
| 8  | Medium   | Move `php-amqplib` to `require-dev`                                      | Trivial | RESOLVED |
| 9  | Medium   | Make deduplication table name configurable                               | Medium  |          |
| 10 | Medium   | Simplify speculative AMQP headers                                        | Small   | RESOLVED |
