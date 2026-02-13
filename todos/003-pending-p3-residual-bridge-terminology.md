---
status: completed
priority: p3
issue_id: "003"
tags: [code-review, naming, cleanup, pr-25]
dependencies: []
---

# Clean up residual "bridge" terminology

## Problem Statement

The old `OutboxToAmqpBridge` was replaced by `OutboxPublishingMiddleware` + `AmqpOutboxPublisher`, but several references to "bridge" remain in variable names, comments, and test method names.

## Findings

- **Pattern Recognition Agent**: Identified residual "bridge" references in 6 locations:
  - `src/DependencyInjection/Compiler/OutboxPublisherPass.php:69` — `$bridge` variable name
  - `src/Amqp/AmqpOutboxPublisher.php:70` — "Forward all stamps from bridge envelope" comment
  - `tests/Unit/InboxFlowTest.php` — multiple "bridge" references in comments
  - `tests/Unit/Factory/EventBusFactory.php:101` — "consumed by bridge" comment
  - `tests/Unit/Amqp/AmqpOutboxPublisherTest.php` — "bridge envelope" references
  - `tests/Functional/OutboxFlowTest.php` — method name `testOutboxBridgePublishesToAmqp` and comments

## Proposed Solutions

### Option A: Rename all occurrences (Recommended)

Replace "bridge" with "middleware" or "publisher" as appropriate.

**Effort:** Small
**Risk:** None — cosmetic changes only

## Acceptance Criteria

- [ ] No references to "bridge" in source code or test comments (outside of git history)
- [ ] Test method names updated
- [ ] Tests pass

## Work Log

| Date | Action | Notes |
|------|--------|-------|
| 2026-02-13 | Created | Found during PR #25 code review |

## Resources

- PR #25: https://github.com/freyr/message-broker/pull/25
