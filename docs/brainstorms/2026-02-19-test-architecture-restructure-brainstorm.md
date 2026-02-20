# Test Architecture Restructure — Phase 1: Pure Unit Tests

**Date:** 2026-02-19
**Updated:** 2026-02-20 (post-review, scoped to phase 1)
**Status:** Brainstorm

## What We're Building

**Phase 1** of a test suite restructure: replace the current mixed unit/functional architecture with pure behaviour-focused unit tests. No infrastructure dependencies whatsoever.

Later phases will add integration and functional tests as needed.

## Why This Approach

The current test suite has problems at both layers:

- **Unit tests are too heavy:** They create full `MessageBus` instances via `EventBusFactory`, use `MiddlewareStackFactory`, `SimpleContainer`, and in-memory transports — effectively mini-integration tests disguised as unit tests. This is ~577 lines of test infrastructure.
- **Functional tests are too broad:** 23 tests requiring Docker with MySQL + RabbitMQ, testing overlapping scenarios with the unit tests, slow feedback loop.
- **For a library**, the contract matters more than end-to-end wiring. Consumers of this bundle will test their own integration.

## Key Decisions

### 1. Delete everything and start fresh
No gradual migration. Delete all existing tests, helpers, factories, and functional infrastructure. Rebuild from scratch.

### 2. Behaviour-focused testing style
Each test verifies a class's behavioural contract:
- Given these inputs (stamps, message types, envelope state)
- What happens? (stamps added/removed, next called or short-circuited, exceptions thrown)
- Use real `Envelope` and `Stamp` objects (they're simple value objects)
- Mock `StackInterface` and infrastructure dependencies
- No bus, no transport, no container

### 3. Serialiser tests use real Symfony Serializer
- `WireFormatSerializer` and `InboxSerializer` use the real Symfony Serializer (pure object, no I/O)
- Test the encode/decode contract: given a message with stamps, verify output headers and body format
- Include real normalizers (`IdNormalizer`, `CarbonImmutableNormalizer`) — they're part of the contract

### 4. Single shared fixtures directory
- One `tests/Fixtures/` directory with 2 well-designed test messages:
  - `TestOutboxEvent` — implements `OutboxMessage` + `#[MessageName]`, with `Id`, `string`, `CarbonImmutable` properties
  - `TestInboxEvent` — plain readonly class (no `OutboxMessage`, no `#[MessageName]`), same property types
- Error-path tests use inline anonymous classes where needed (e.g., `OutboxMessage` without `#[MessageName]`)

### 5. Contract/attribute tests belong in `freyr/message-broker-contracts`
- `MessageName` attribute validation and `ResolvesFromClass` trait live in a separate Composer package
- These should be tested in that package's own test suite, not here

## Target Directory Structure

```
tests/
├── bootstrap.php                           # Minimal: autoloader only
├── Fixtures/                               # Shared test messages
│   ├── TestOutboxEvent.php                 # OutboxMessage + #[MessageName] + value objects
│   └── TestInboxEvent.php                  # Plain readonly class (inbox-side)
└── Unit/                                   # All behaviour-focused tests (flat)
    ├── MessageIdStampMiddlewareTest.php
    ├── MessageNameStampMiddlewareTest.php
    ├── OutboxPublishingMiddlewareTest.php
    ├── DeduplicationMiddlewareTest.php
    ├── WireFormatSerializerTest.php
    ├── InboxSerializerTest.php
    ├── NormalizerTest.php                  # Both normalizers in one class
    ├── SetupDeduplicationCommandTest.php
    ├── DeduplicationStoreCleanupTest.php
    ├── ConfigurationTest.php
    ├── OutboxPublisherPassTest.php
    ├── FreyrMessageBrokerExtensionTest.php
    └── IdTypeTest.php
```

**13 test classes**, flat directory.

## Comprehensive Scenario List

### Middleware: MessageIdStampMiddleware
1. Non-OutboxMessage passes through unchanged
2. OutboxMessage with ReceivedStamp (redelivery) passes through unchanged
3. OutboxMessage without MessageIdStamp gets stamped with UUID v7
4. OutboxMessage with existing MessageIdStamp is not re-stamped

### Middleware: MessageNameStampMiddleware
1. Non-OutboxMessage passes through unchanged
2. OutboxMessage with ReceivedStamp passes through unchanged
3. OutboxMessage with `#[MessageName]` gets MessageNameStamp added
4. OutboxMessage already having MessageNameStamp is not re-stamped
5. OutboxMessage without `#[MessageName]` attribute throws RuntimeException

### Middleware: OutboxPublishingMiddleware
1. Non-OutboxMessage passes through to next middleware
2. OutboxMessage without ReceivedStamp passes through (dispatch path, not consume path)
3. OutboxMessage consumed from transport without registered publisher logs at debug level and passes through
4. OutboxMessage consumed from outbox transport without MessageIdStamp throws RuntimeException
5. OutboxMessage consumed from outbox transport without MessageNameStamp throws RuntimeException
6. OutboxMessage consumed from outbox transport delegates to resolved publisher
7. Publisher receives clean envelope (OutboxMessage + MessageIdStamp + MessageNameStamp only, no transport stamps)
8. Short-circuits after publishing (does not call stack.next)

### Middleware: DeduplicationMiddleware
1. Message without ReceivedStamp passes through (dispatch path)
2. Received message without MessageIdStamp passes through (no dedup possible)
3. Received message with MessageIdStamp — new message (store returns false) — calls next middleware and propagates its return value
4. Received message with MessageIdStamp — duplicate (store returns true) — short-circuits, skips handler

### Serializer: WireFormatSerializer
1. encode(): Extracts semantic name from MessageNameStamp, sets `type` header
2. encode(): Sets `X-Message-Class` header with original FQN
3. encode(): Missing MessageNameStamp throws RuntimeException
4. encode(): Body contains only business data (no messageId)
5. decode(): Restores FQN from `X-Message-Class` header, replaces semantic `type`
6. decode(): Without `X-Message-Class` header — falls back to semantic name as type
7. Round-trip: encode→decode preserves message integrity, stamps, and business data

### Serializer: InboxSerializer
1. decode(): Translates semantic `type` header to PHP FQN via message_types mapping
2. decode(): Missing `type` header throws MessageDecodingFailedException
3. decode(): Empty `type` header throws MessageDecodingFailedException
4. decode(): Non-array headers throws MessageDecodingFailedException
5. decode(): Unknown message type throws MessageDecodingFailedException with config guidance
6. decode(): Adds MessageNameStamp with semantic name if not already present
7. decode(): Preserves existing MessageNameStamp if already present (retry path)
8. decode(): Preserves stamps from `X-Message-Stamp-*` headers
9. encode(): Uses MessageNameStamp to set semantic `type` header (retry/failed path)
10. encode(): Without MessageNameStamp preserves FQN in `type` header (parent default)
11. Round-trip: decode→encode preserves semantic name for retry scenarios

### Normalizer: NormalizerTest (both normalizers, one class)
1. IdNormalizer: round-trip normalize→denormalize preserves Id value
2. IdNormalizer: denormalize() with non-string input throws InvalidArgumentException
3. CarbonImmutableNormalizer: round-trip normalize→denormalize preserves timestamp
4. CarbonImmutableNormalizer: denormalize() with non-string input throws InvalidArgumentException

### Command: SetupDeduplicationCommand
1. Dry-run mode (default): outputs SQL without executing
2. `--force` mode: creates table via mocked SchemaManager
3. `--force` with table already existing: appropriate error message
4. `--migration` mode: generates Doctrine migration file (clean up in tearDown)
5. `--force` and `--migration` together: error (mutual exclusivity)
6. Missing migrations config: error with guidance

### Command: DeduplicationStoreCleanup
1. Default `--days`: verifies mocked Connection receives correct SQL with `30` parameter
2. Custom `--days` parameter: verifies mocked Connection receives specified value
3. Reports number of deleted rows from mocked Connection result

### DependencyInjection: Configuration
1. Default config: empty message_types, default table name
2. Custom message_types mapping accepted
3. Custom table name accepted
4. Invalid table name (SQL injection chars) rejected
5. Table name starting with number rejected
6. Table name with only valid chars (alphanumeric + underscore) accepted

### DependencyInjection: OutboxPublisherPass
1. No tagged services: middleware gets empty locator
2. Tagged service with valid transport: registered in locator
3. Tagged service missing `transport` attribute: throws error
4. Duplicate transport (two publishers claiming same transport): throws error
5. Tagged service not implementing OutboxPublisherInterface: throws error

### DependencyInjection: FreyrMessageBrokerExtensionTest
1. Loads `config/services.yaml` without errors
2. Sets `message_broker.inbox.message_types` parameter from config
3. Sets `message_broker.inbox.deduplication.table_name` parameter from config
4. Key service definitions exist (middleware, serialisers, store)

### Doctrine: IdType
1. convertToPHPValue(): binary string → Id
2. convertToPHPValue(): null → null
3. convertToPHPValue(): Id instance → returns unchanged (early return)
4. convertToPHPValue(): non-string, non-null, non-Id → throws InvalidArgumentException
5. convertToDatabaseValue(): Id → binary string
6. convertToDatabaseValue(): null → null
7. convertToDatabaseValue(): non-Id, non-null → throws InvalidArgumentException
8. getSQLDeclaration(): returns BINARY(16)
9. getName(): returns 'id_binary'
10. requiresSQLCommentHint(): returns true

## Estimated Scope

- **~65 unit test methods** across 13 test classes
- **Zero infrastructure dependencies** — no Docker, no database, no message broker
- **Sub-second test execution**
- **~577 lines of test infrastructure deleted**, replaced by direct mocking

## Future Phases

- **Phase 2:** Integration tests (SQLite in-memory for `DeduplicationDbalStore`, command SQL execution)
- **Phase 3:** Functional tests (transaction rollback guarantees, end-to-end flows, AMQP wire format)
