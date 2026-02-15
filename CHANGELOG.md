# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.0] - 2026-02-15

### Changed

- **BREAKING: Package split — AMQP and contracts extracted into separate packages**
  - Contracts (stamps, interfaces) moved to `freyr/message-broker-contracts` (^0.1)
  - AMQP bridge, routing strategy, and topology moved to `freyr/message-broker-amqp`
  - Core package is now transport-agnostic — no direct AMQP dependency
  - **Migration path**: Add `freyr/message-broker-amqp` to your `composer.json` and update `use` statements from `Freyr\MessageBroker\...` to `Freyr\MessageBroker\Contracts\...` for stamps and interfaces

- **BREAKING: Transport-agnostic outbox architecture**
  - `OutboxToAmqpBridge` replaced by generic `OutboxPublishingMiddleware` + `OutboxPublisherInterface`
  - Publishers are collected via `message_broker.outbox_publisher` service tag and `OutboxPublisherPass` compiler pass
  - Any transport (AMQP, SNS, HTTP, etc.) can be plugged in by implementing `OutboxPublisherInterface`

- **BREAKING: Stamp-first middleware pattern**
  - `MessageIdStampMiddleware` generates `MessageIdStamp` at dispatch time (previously generated in bridge)
  - New `MessageNameStampMiddleware` extracts semantic name from `#[MessageName]` at dispatch time
  - Stamps are now the single source of truth for message metadata — serialisers read stamps instead of reflecting on classes

- **BREAKING: Namespace changes** — the following classes moved to `freyr/message-broker-contracts`:
  - `Freyr\MessageBroker\Inbox\MessageIdStamp` → `Freyr\MessageBroker\Contracts\MessageIdStamp`
  - `Freyr\MessageBroker\Inbox\DeduplicationStore` → `Freyr\MessageBroker\Contracts\DeduplicationStore`
  - `Freyr\MessageBroker\Outbox\EventBridge\OutboxMessage` → `Freyr\MessageBroker\Contracts\OutboxMessage`
  - `Freyr\MessageBroker\Outbox\MessageName` → `Freyr\MessageBroker\Contracts\MessageName`
  - `Freyr\MessageBroker\Serializer\MessageNameStamp` → `Freyr\MessageBroker\Contracts\MessageNameStamp`

- **BREAKING: `DeduplicationStore::isDuplicate()` signature change** — accepts `Id` instead of `string` for messageId parameter

- **BREAKING: `OutboxSerializer` replaced by `WireFormatSerializer`**
  - Reads semantic name from `MessageNameStamp` instead of reflecting on `#[MessageName]` attribute
  - Cleaner separation: serialiser handles wire format only, metadata extraction is middleware's responsibility

- **BREAKING: Enabled `auto_setup: true` for Doctrine Messenger transports** (outbox, failed)
  - `messenger_outbox` and `messenger_messages` tables are now auto-created by Symfony Messenger
  - `migrations/schema.sql` reduced to only `message_broker_deduplication` table
  - **Migration path for existing installations**: Tables are not dropped, only management changes

- **BREAKING: PHP requirement lowered from 8.4 to 8.2** — typed constants changed to untyped for compatibility
- Configurable deduplication table name via `message_broker.inbox.deduplication_table_name` config option
- `DeduplicationStoreCleanup` command now uses configurable table name

### Added

- `OutboxPublishingMiddleware` — generic middleware that delegates to transport-specific `OutboxPublisherInterface` implementations via service locator
- `OutboxPublisherPass` compiler pass — collects services tagged with `message_broker.outbox_publisher` into the middleware's service locator
- `MessageIdStampMiddleware` — stamps `OutboxMessage` envelopes with `MessageIdStamp` at dispatch time
- `MessageNameStampMiddleware` — stamps `OutboxMessage` envelopes with `MessageNameStamp` at dispatch time
- `WireFormatSerializer` — wire format serialiser for AMQP publishing (replaces `OutboxSerializer`)
- Configurable deduplication table name (`deduplication_table_name` in bundle configuration)
- Comprehensive functional test suite (Outbox flow, Inbox flow, deduplication edge cases, transaction rollback)
- Phase 1 critical data integrity tests
- CI pipeline with ECS and PHPStan (level max, 0 errors)
- Test matrix covering PHP 8.2+ and Symfony 6.4+

### Fixed

- **MessageIdStamp generated at dispatch time** — ensures stable IDs that survive outbox redelivery, fixing deduplication reliability
- `DeduplicationMiddleware` now `final readonly` with proper logger injection
- All PHPStan level max errors resolved (119 → 0)
- ORM entity mapping moved to test directory (not shipped with package)
- Recipe serialiser class references corrected

### Removed

- `OutboxToAmqpBridge` — replaced by `OutboxPublishingMiddleware` + `OutboxPublisherInterface`
- `OutboxSerializer` — replaced by `WireFormatSerializer`
- `MessageIdStamp`, `DeduplicationStore`, `OutboxMessage`, `MessageName`, `MessageNameStamp` — moved to `freyr/message-broker-contracts`
- `AmqpRoutingKey`, `AmqpRoutingStrategyInterface`, `DefaultAmqpRoutingStrategy`, `MessengerTransport`, `AmqpExchange` — moved to `freyr/message-broker-amqp`
- `PropertyPromotionObjectNormalizer` service (redundant)
- Manual messenger table definitions from `migrations/schema.sql` (now auto-managed by Symfony)
- `.idea/` project files from version control

## [0.2.3] - 2026-01-29

### Fixed

- **Critical: Wrong table name in cleanup command** - `DeduplicationStoreCleanup` command now uses correct table name `message_broker_deduplication` instead of non-existent `deduplication_store`
- **MessageNameStamp duplication on retry** - Added existence checks before appending `MessageNameStamp` in both serialisers to prevent stamp accumulation during retry/failed scenarios

### Documentation

- **Removed non-existent features** - Cleaned up documentation to remove references to features that were never implemented:
  - Removed `inbox:ingest` command references (never existed)
  - Removed `inbox://` transport references (never existed)
  - Removed `messenger_inbox` table references (incorrect table name)
  - Removed "Automatic DLQ Routing" feature claim (standard Symfony failed transport is used)
  - Removed `dlq_transport` configuration (never implemented)
- **Fixed incorrect attribute names** - Changed `#[AmqpExchange]` to correct `#[MessengerTransport]` attribute throughout documentation
- **Fixed serialiser terminology** - Corrected all references from non-existent `MessageNameSerializer` to actual classes:
  - Publishing: `OutboxSerializer`
  - Consuming: `InboxSerializer`
- **Clarified deduplication mechanism** - Documented that deduplication uses `MessageIdStamp` + PHP class FQN (not `MessageNameStamp`)
- **Fixed directory structure** - Updated `CLAUDE.md` directory tree to match actual file locations and structure
- **Fixed cross-references** - Corrected `amqp-routing-guide.md` → `amqp-routing.md`

### Added

- **Database schema documentation** - New comprehensive `docs/database-schema.md` file with:
  - Complete 3-table architecture explanation
  - Full SQL schemas for all tables
  - Migration examples
  - Cleanup strategies and commands
  - Performance optimisation notes
  - Transport configuration examples
  - Message flow diagrams

### Changed

- **Consolidated documentation** - Reduced duplication by creating single source of truth for 3-table architecture in `docs/database-schema.md`
- **Improved README** - Updated documentation index with links to all architecture docs
- **Code cleanup** - Applied coding standards and improved code consistency across codebase
- **British English** - Standardised spelling throughout documentation (serialiser, optimised, customisation)

### Development

- Added `.idea/symfony2.xml` configuration
- Updated `.gitignore` to exclude `cache/` directory
- Updated `ecs.php` coding standards configuration
- Improved `composer.json` structure

## [0.2.2] - 2025-10-16

### Fixed

- **Critical: Serializer retry bug** - Both `InboxSerializer` and `OutboxSerializer` now properly preserve semantic message names during retry/failed message scenarios
  - `InboxSerializer`: Stores semantic name in `MessageNameStamp` during decode(), restores it during encode() for retries
  - `OutboxSerializer`: Stores FQN in `X-Message-Class` header during encode(), restores it during decode() for retries
  - Previously, retried messages would fail with "Unknown message type" error due to lost semantic name mapping
- **Transport configuration clarity** - Fixed documentation to clarify that outbox transport should use default serializer, not `OutboxSerializer`

### Changed

- **Improved code consistency** - Refactored both serializers to have mirror-image structure with consistent naming and documentation
- **Enhanced test coverage** - Added proper transport separation in tests (outbox storage, AMQP publish, AMQP consume)
- **Test factory improvements** - Tests now use `PropertyInfoExtractor` with `ObjectNormalizer` to match production configuration

### Documentation

- Updated messenger.yaml example to show correct serializer configuration per transport
- Clarified 3-transport architecture: outbox storage, AMQP publish (OutboxSerializer), AMQP consume (InboxSerializer)
- Updated service configuration comments to reflect bidirectional serialization behavior
- Added comprehensive flow documentation for retry scenarios

## [0.2.1] - 2025-10-14

### Changed

- Expand `freyr/identity` dependency to support both `^0.2` and `^0.3` versions

## [0.2.0] - 2025-10-12

### Changed

- **BREAKING: Split MessageNameSerializer** - Separated into `InboxSerializer` and `OutboxSerializer` for cleaner separation of concerns
  - `InboxSerializer`: Decodes semantic names to FQN, uses default encoding for failed message retries
  - `OutboxSerializer`: Encodes producer messages with semantic names from `#[MessageName]` attribute
- **Native Serializer Integration** - Both serializers now inject Symfony's native `@serializer` service instead of creating custom instances
- **Property Promotion Support** - Added `PropertyPromotionObjectNormalizer` with `propertyTypeExtractor` for PHP 8 constructor property promotion
- **Improved Service Configuration** - DeduplicationMiddleware now properly uses `DeduplicationStore` interface with `DeduplicationDbalStore` implementation

### Added

- **Extensible Normalizer System** - Applications can easily add custom normalizers by tagging them with `serializer.normalizer`
- **Auto-discovery of Normalizers** - Package normalizers automatically registered via service tags
- **PropertyPromotionObjectNormalizer** - Custom ObjectNormalizer configured with property type extraction for better type handling

### Fixed

- Failed message retry safety - Inbox messages without `#[MessageName]` attribute can now be properly encoded for failed transport
- Service definitions for DeduplicationMiddleware - Now correctly injects DeduplicationStore interface

### Documentation

- Updated all configuration examples to reflect split serializer architecture
- Added explanation of "Fake FQN" pattern with separate serializers
- Enhanced custom normalizer documentation with priority system details
- Updated service configuration examples in CLAUDE.md and README.md

## [0.1.0] - 2025-10-10

### Added

- **Transactional Outbox Pattern** - Publish events reliably within database transactions
- **Inbox Pattern with Deduplication** - Middleware-based exactly-once processing using binary UUID v7
- **Semantic Message Naming** - Language-agnostic message names via `#[MessageName]` attribute
- **AMQP Routing Strategy** - Convention-based routing with attribute overrides
- **OutboxMessage Interface** - Marker interface for type-safe outbox events
- **MessageNameSerializer** - Unified serializer supporting both inbox and outbox patterns
- **DeduplicationMiddleware** - Transactional deduplication for consumed messages
- **Binary UUID v7 Support** - Custom Doctrine type for message identifiers (used in deduplication)
- **Custom Normalizers** - IdNormalizer and CarbonImmutableNormalizer for value objects
- **Deduplication Cleanup Command** - Remove old idempotency records
- **3-Table Architecture** - Dedicated tables for outbox, deduplication, and failed messages

### Documentation

- Outbox Pattern - Transactional consistency principles
- Inbox Deduplication - Exactly-once processing guarantees
- Message Serialization - Semantic naming and cross-language compatibility
- AMQP Routing - Convention-based routing with customization options

[0.3.0]: https://github.com/freyr/message-broker/releases/tag/v0.3.0
[0.2.3]: https://github.com/freyr/message-broker/releases/tag/v0.2.3
[0.2.2]: https://github.com/freyr/message-broker/releases/tag/v0.2.2
[0.2.1]: https://github.com/freyr/message-broker/releases/tag/v0.2.1
[0.2.0]: https://github.com/freyr/message-broker/releases/tag/v0.2.0
[0.1.0]: https://github.com/freyr/message-broker/releases/tag/v0.1.0
