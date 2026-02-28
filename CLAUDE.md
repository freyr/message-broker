# Freyr Message Broker

Core Symfony bundle: Inbox/Outbox patterns with transactional guarantees and automatic deduplication.

## Domain Rules

- **OUTBOX001**: Every outbox event MUST have `#[MessageName('domain.action')]` AND implement `OutboxMessage`.
- **OUTBOX002**: No `messageId` property on events — `MessageIdStampMiddleware` auto-generates UUID v7 at dispatch.
- **SCHEMA001**: Three-table architecture: `messenger_outbox`, `message_broker_deduplication`, `messenger_messages`.
- **SERIAL001**: Split serialisers — `WireFormatSerializer` (FQN→semantic for publishing), `InboxSerializer` (semantic→FQN for consumption).
- **DEDUP001**: `DeduplicationMiddleware` runs at priority -10 (after `doctrine_transaction`). Atomic commit of dedup entry + handler changes.

## Architecture

**Outbox flow:**
`Event → MessageIdStampMiddleware → MessageNameStampMiddleware → doctrine_transaction → OutboxPublishingMiddleware → Transport`

**Inbox flow:**
`Transport → InboxSerializer (semantic→FQN) → doctrine_transaction → DeduplicationMiddleware → Handler`

Key patterns:
- `OutboxPublishingMiddleware` delegates to `OutboxPublisherInterface` via service locator
- Direct `SenderInterface` send — no nested bus dispatch, no nested savepoints
- **Ordered outbox (optional):** `ordered-doctrine://` DSN + `PartitionKeyStamp`. See [ordered delivery guide](docs/ordered-delivery.md).

Namespace: `Freyr\MessageBroker\*`

## Common Tasks

| Task | Guide |
|------|-------|
| Configuration & setup | [README.md](README.md) |
| Database schema & migrations | [docs/database-schema.md](docs/database-schema.md) |
| Inbox deduplication | [docs/inbox-deduplication.md](docs/inbox-deduplication.md) |
| Message serialisation | [docs/message-serialization.md](docs/message-serialization.md) |
| Outbox pattern | [docs/outbox-pattern.md](docs/outbox-pattern.md) |
| Ordered delivery | [docs/ordered-delivery.md](docs/ordered-delivery.md) |
| Critical patterns | See `docs/solutions/patterns/` in workspace root |

## Git Conventions

See [docs/git-conventions.md](docs/git-conventions.md) for full guide.

- **Branches:** `<issue-number>-description` (e.g., `10-add-retry-mechanism`)
- **Commits:** Conventional Commits with issue refs
- **PRs:** Use `Fixes #N` / `Closes #N` in body
- **Identity:** Before `gh` commands, run `gh auth switch --user freyr`

## Boundaries

**ASK FIRST:**
- Database schema changes
- Changes to middleware ordering
- Changes to serialiser architecture

## References

- [docs/](docs/) — Detailed architecture and pattern documentation
- [AMQP extraction learnings](docs/AMQP_EXTRACTION_LEARNINGS.md)
