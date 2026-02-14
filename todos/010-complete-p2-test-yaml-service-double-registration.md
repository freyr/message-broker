---
status: complete
priority: p2
issue_id: "010"
tags: [code-review, architecture, tests, configuration, functional-tests]
dependencies: []
---

# Remove redundant service re-declarations from test.yaml

## Problem Statement

`tests/Functional/config/test.yaml` re-declares services that the bundle already loads via `config/services.yaml` through `FreyrMessageBrokerExtension`. This means functional tests may exercise overridden service definitions rather than the actual bundle wiring, reducing confidence that the bundle works as shipped.

## Findings

- **Architecture Agent**: Identified double-registration of 8+ services; "if the bundle's `services.yaml` changes, the test configuration may silently shadow it with a stale definition"
- **Simplicity Agent**: Did not flag this (focused on test code, not config)

## Redundant Services in test.yaml

Lines 123-168 re-declare services already loaded by the bundle:

- `Freyr\MessageBroker\Serializer\Normalizer\:` (line 128)
- `Freyr\MessageBroker\Serializer\InboxSerializer` (line 133)
- `Freyr\MessageBroker\Serializer\WireFormatSerializer` (line 139)
- `Freyr\MessageBroker\Inbox\DeduplicationMiddleware` (line 153)
- `Freyr\MessageBroker\Outbox\MessageIdStampMiddleware` (line 160)
- `Freyr\MessageBroker\Outbox\MessageNameStampMiddleware` (line 163)
- `Freyr\MessageBroker\Outbox\OutboxPublishingMiddleware` (line 166)

**Legitimate overrides to keep:**
- `DeduplicationStore` marked `public: true` (needed for direct test access)
- `NullLogger` override (suppresses noise)
- Test-specific handlers/publishers

## Proposed Solutions

### Option A: Remove redundant, keep only genuine overrides (Recommended)

Remove service definitions that match the bundle defaults. Keep only:
- `public: true` on `DeduplicationStore`
- `NullLogger` binding
- Test fixture registrations (handlers, publishers)

**Pros:** Tests exercise actual bundle wiring; catches bundle config regressions
**Cons:** May surface issues if bundle wiring differs from what tests expect
**Effort:** Medium (need to verify each removal)
**Risk:** Low-Medium — may reveal hidden mismatches

## Acceptance Criteria

- [ ] No redundant service declarations in test.yaml
- [ ] Only genuine test overrides remain (public services, null logger, fixtures)
- [ ] All tests pass

## Work Log

| Date | Action | Notes |
|------|--------|-------|
| 2026-02-14 | Created | Found during functional test review (architecture agent) |
