---
status: complete
priority: p3
issue_id: "012"
tags: [code-review, duplication, tests, functional-tests]
dependencies: ["005", "006", "008"]
---

# Reduce AMQP header boilerplate duplication across tests

## Problem Statement

The stamp header key `'X-Message-Stamp-Freyr\MessageBroker\Contracts\MessageIdStamp'` appears as a raw string ~24 times across functional tests. The string `'test.event.sent'` appears ~28 times. This makes tests fragile if these values change.

## Findings

- **Pattern Recognition Agent**: Identified 24 occurrences of the stamp header; recommended extracting to constant using `MessageIdStamp::class` concatenation
- **Simplicity Agent**: Noted all tests use `publishToAmqp()` directly with hand-assembled headers instead of existing convenience helpers

## Proposed Solutions

### Option A: Extract constants + use helper methods (Recommended)

Add to `FunctionalTestCase`:
```php
private const MESSAGE_ID_STAMP_HEADER = 'X-Message-Stamp-' . MessageIdStamp::class;
private const TEST_EVENT_TYPE = 'test.event.sent';
```

Then either create slim helpers or update remaining tests to use constants.

**Pros:** Single source of truth, ~120 lines of boilerplate reduced
**Cons:** Requires touching many test files
**Effort:** Medium
**Risk:** None

## Acceptance Criteria

- [ ] Stamp header key defined as constant
- [ ] Semantic message name defined as constant
- [ ] No raw string occurrences of either value in tests

## Work Log

| Date | Action | Notes |
|------|--------|-------|
| 2026-02-14 | Created | Found during functional test review (pattern recognition agent) |
