# Plan Review: Transport-Agnostic Architecture

**Date:** 2026-02-13
**Plan:** `docs/plans/2026-02-13-refactor-transport-agnostic-architecture-plan.md`
**Reviewers:** architecture-strategist, code-simplicity-reviewer, pattern-recognition-specialist

---

## Review Findings Applied

### CRITICAL — Interface simplified (unanimous, 3/3 reviewers)

`$messageName` removed from `OutboxPublisherInterface::publish()`. The publisher now extracts the message name from `MessageNameStamp` on the envelope — single source of truth.

**Before:** `publish(Envelope $envelope, string $messageName): void`
**After:** `publish(Envelope $envelope): void`

### IMPORTANT — Stamp forwarding (architecture reviewer)

`AmqpOutboxPublisher` now uses `$envelope->with(new AmqpStamp(...))` instead of rebuilding the envelope from scratch. This forwards all stamps from the bridge (MessageIdStamp, MessageNameStamp) and prevents silent stamp loss.

### IMPORTANT — Namespace consolidation (architecture + pattern reviewers)

Three files relocated to better namespaces:
- `MessageNameStamp` → `Freyr\MessageBroker\Stamp\` (alongside MessageIdStamp)
- `OutboxMessage` → `Freyr\MessageBroker\Outbox\` (out of orphaned EventBridge/)
- `ResolvesFromClass` → `Freyr\MessageBroker\Attribute\` (shared, avoids cross-boundary dep)

### IMPORTANT — Phases merged (architecture reviewer + learnings)

Phase 3 (Configuration) and Phase 4 (Test Migration) merged into a single atomic phase. Project learnings mandate that `config/services.yaml` and `tests/Functional/config/test.yaml` are updated in the same commit.

### IMPORTANT — Log level consistency (pattern reviewer)

Both `OutboxPublishingMiddleware` and `AmqpOutboxPublisher` now log at `debug` level consistently.

### IMPORTANT — Null-safe operator fixed (pattern reviewer)

`$messageIdStamp?->messageId` changed to `$messageIdStamp->messageId` in `AmqpOutboxPublisher` — the bridge guarantees the stamp is present.

### SUGGESTION — MessageName regex relaxed (architecture reviewer)

Changed from `/\A[a-z][a-z0-9]*(\.[a-z][a-z0-9]*){1,10}\z/` to `/\A[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)+\z/` — removes arbitrary upper bound on segments.

---

## YAGNI Concerns — Overridden by User

The simplicity reviewer recommended a minimal 3-file path. This was overridden: SQS implementation will be created alongside AMQP, justifying the full plan including compiler pass, YAML routing overrides, namespace moves, and plugin extraction boundary.

---

## Final Plan Structure (5 phases)

1. **Phase 1:** Core Abstraction + Namespace Moves (interface, middleware, compiler pass, file relocations)
2. **Phase 2:** AMQP Plugin Implementation (publisher extraction, routing class moves)
3. **Phase 3:** Configuration + Test Migration (atomic — services.yaml + test.yaml in same commit)
4. **Phase 4:** Cleanup and Delete (old files, empty directories, stale reference check)
5. **Phase 5:** Documentation (README, CLAUDE.md, docs/amqp-routing.md)
