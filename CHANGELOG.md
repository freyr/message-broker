# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[0.1.0]: https://github.com/freyr/message-broker/releases/tag/0.1.0
