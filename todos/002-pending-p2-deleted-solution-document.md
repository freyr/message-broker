---
status: completed
priority: p2
issue_id: "002"
tags: [code-review, documentation, compound-engineering, pr-25]
dependencies: []
---

# Restore deleted compound-engineering solution document

## Problem Statement

PR #25 deletes `../docs/solutions/test-failures/deduplication-middleware-not-running-in-tests.md` (460 lines) in commit `9db6457` without explanation. This file is a compound-engineering pipeline artifact documenting a previously solved problem. The deletion was bundled into an unrelated commit ("add core abstraction layer and consolidate namespaces") with no mention in the commit message.

Per project conventions in `CLAUDE.md`, files in `../docs/solutions/` are institutional knowledge and should not be removed without deliberation.

## Findings

- **Git History Agent**: "460-line document from a previous issue (#2). Deletion is unrelated to the transport-agnostic refactoring. Commit message does not mention this deletion."
- The file documented the solution for `DeduplicationMiddleware` not running in Symfony Messenger functional tests — still relevant knowledge.

## Proposed Solutions

### Option A: Restore the file (Recommended)

Restore from git: `git checkout main -- ../docs/solutions/test-failures/deduplication-middleware-not-running-in-tests.md`

**Pros:** Preserves institutional knowledge. Simple.
**Cons:** None.
**Effort:** Small
**Risk:** None

### Option B: Intentionally delete with explanation

If the document is genuinely outdated, delete it in a separate `docs:` commit with an explanation.

**Pros:** Clean history.
**Cons:** Loses institutional knowledge if content is still valid.
**Effort:** Small
**Risk:** Low

## Technical Details

- **Affected file:** `../docs/solutions/test-failures/deduplication-middleware-not-running-in-tests.md`
- **Deleted in commit:** `9db6457`

## Acceptance Criteria

- [ ] File is either restored or intentionally deleted in a separate commit with explanation
- [ ] Compound-engineering solution documents are not silently deleted

## Work Log

| Date | Action | Notes |
|------|--------|-------|
| 2026-02-13 | Created | Found during PR #25 code review |

## Resources

- PR #25: https://github.com/freyr/message-broker/pull/25
