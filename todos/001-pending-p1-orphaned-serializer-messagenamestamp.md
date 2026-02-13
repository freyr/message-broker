---
status: completed
priority: p1
issue_id: "001"
tags: [code-review, cleanup, dead-code, pr-25]
dependencies: []
---

# Delete orphaned `src/Serializer/MessageNameStamp.php`

## Problem Statement

The file `src/Serializer/MessageNameStamp.php` was not deleted when `MessageNameStamp` was moved to `src/Stamp/MessageNameStamp.php` during the namespace consolidation in PR #25. The old file is completely unreferenced — no `use Freyr\MessageBroker\Serializer\MessageNameStamp` import exists anywhere in the codebase. All code imports `Freyr\MessageBroker\Stamp\MessageNameStamp` instead.

This dead file could cause autoloader confusion and lead developers to accidentally import the wrong class.

## Findings

- **Pattern Recognition Agent**: Flagged as "CRITICAL — dead file that could confuse developers"
- **Git History Agent**: Confirmed orphaned file — "never imported anywhere, should have been deleted in commit 1 or commit 4"
- **Code Simplicity Agent**: Independently confirmed same finding
- **Verification**: `grep -r 'Serializer\\MessageNameStamp' src/ tests/` returns zero results

## Proposed Solutions

### Option A: Delete the file (Recommended)

**Pros:** Simple, immediate fix. No risk.
**Cons:** None.
**Effort:** Small (1 line: `git rm src/Serializer/MessageNameStamp.php`)
**Risk:** None

## Technical Details

- **Affected file:** `src/Serializer/MessageNameStamp.php`
- **Replacement:** `src/Stamp/MessageNameStamp.php` (already exists and is used everywhere)

## Acceptance Criteria

- [ ] `src/Serializer/MessageNameStamp.php` is deleted
- [ ] All tests pass (108 tests)
- [ ] PHPStan clean

## Work Log

| Date | Action | Notes |
|------|--------|-------|
| 2026-02-13 | Created | Found during PR #25 code review |

## Resources

- PR #25: https://github.com/freyr/message-broker/pull/25
