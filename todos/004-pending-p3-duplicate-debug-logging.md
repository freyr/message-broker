---
status: completed
priority: p3
issue_id: "004"
tags: [code-review, performance, logging, pr-25]
dependencies: []
---

# Remove duplicate debug logging between middleware and publisher

## Problem Statement

Both `OutboxPublishingMiddleware` (line 78) and `AmqpOutboxPublisher` (line 75) emit overlapping `debug` log entries for every message. The publisher's log is strictly more informative (includes `sender` and `routing_key`), making the middleware's log redundant.

## Findings

- **Performance Oracle**: "Consider removing the debug log line in OutboxPublishingMiddleware (line 78-83). The publisher's log contains strictly more information."
- In `debug` mode, this doubles log volume for outbox processing.

## Proposed Solutions

### Option A: Remove middleware debug log (Recommended)

Remove the "Delegating outbox event to transport publisher" log line from `OutboxPublishingMiddleware`. The `AmqpOutboxPublisher` "Publishing event to AMQP" log includes all relevant context.

**Effort:** Small
**Risk:** None

### Option B: Keep both (Accept as-is)

The duplicate logging provides traceability at both abstraction layers.

## Acceptance Criteria

- [ ] Only one debug log line per published message (or deliberate decision to keep both)

## Work Log

| Date | Action | Notes |
|------|--------|-------|
| 2026-02-13 | Created | Found during PR #25 code review |

## Resources

- PR #25: https://github.com/freyr/message-broker/pull/25
