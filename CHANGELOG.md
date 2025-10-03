# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2025-10-03

### Added
- Initial release of Freyr Message Broker bundle
- Outbox pattern implementation with transactional guarantees
- Inbox pattern implementation with automatic deduplication using binary UUID v7
- Custom Doctrine transport for inbox with INSERT IGNORE support
- Strategy-based publishing architecture for outbox events
- AMQP publishing strategy with convention-based routing
- Generic outbox bridge handler with automatic DLQ routing
- Typed inbox serializer for type-safe message handling
- Commands for AMQP ingestion, setup, and outbox cleanup
- Support for Symfony 6.4+ and 7.x
- Comprehensive documentation and examples
- FreyrMessageBrokerBundle with DependencyInjection support
- Semantic configuration (`message_broker`) for inbox message types and outbox settings

### Features
- **3-table architecture**: Separate tables for inbox, outbox, and failed messages
- **Binary UUID v7**: Database-level deduplication with binary(16) primary keys
- **Strategy pattern**: Extensible publishing system supporting AMQP, HTTP, SQS, etc.
- **Convention-based routing**: Automatic AMQP exchange/routing key determination
- **Attribute overrides**: `#[MessageName]`, `#[AmqpExchange]`, `#[AmqpRoutingKey]`
- **Horizontal scaling**: SKIP LOCKED support for concurrent workers
- **At-least-once delivery**: Guaranteed event delivery with idempotent consumers

[Unreleased]: https://github.com/freyr/message-broker/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/freyr/message-broker/releases/tag/v0.1.0
