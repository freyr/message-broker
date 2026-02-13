# Code Review: PR #25 — Extract transport-agnostic core with OutboxPublisherInterface

**Review Date:** 2026-02-13
**Branch:** `24-transport-agnostic-architecture`
**PR:** https://github.com/freyr/message-broker/pull/25
**Diff:** +1305/-1014 across 45 files (5 commits)

## Review Agents Used

- Architecture Strategist
- Security Sentinel
- Performance Oracle
- Pattern Recognition Specialist
- Code Simplicity Reviewer
- Git History Analyser

## Executive Summary

The refactoring is well-executed with clean separation between the transport-agnostic core (`Outbox\`) and the AMQP plugin (`Amqp\`). All design patterns are correctly applied, performance overhead is negligible (sub-microsecond per message), and no critical security vulnerabilities were introduced. The architecture is ready for future transport implementations (SQS, Kafka).

**Two issues block merge:** an orphaned dead file and an accidentally deleted solution document.

## Architecture Assessment

**Verdict: Sound design with clean plugin boundary.**

- No AMQP imports exist in core namespaces — verified by moving `ext-amqp` and `symfony/amqp-messenger` to `require-dev`
- The `OutboxPublisherInterface` is appropriately scoped (single `publish(Envelope)` method)
- Compiler pass (`OutboxPublisherPass`) is exemplary Symfony pattern with type assertion, duplicate detection, and graceful empty handling
- Two-level service locator is justified by core/plugin separation
- Test coverage is comprehensive: 8 tests for middleware, 6 for compiler pass, 7 for publisher, 9 for routing strategy

## Security Assessment

**Overall Risk: LOW.** No new vulnerabilities introduced.

Pre-existing findings noted (not introduced by this PR):
- OutboxSerializer::decode() trusts `X-Message-Class` header without validation (contrast with InboxSerializer's whitelist approach)
- Table name in DeduplicationStoreCleanup not validated against SQL-safe characters

## Performance Assessment

**Verdict: Acceptable.** Sub-microsecond overhead per message.

- Service locators are lazy and cached (2 extra `isset()` calls vs old code)
- Attribute reflection cached via `ResolvesFromClass` static cache
- Positive change: logging downgraded from `info` to `debug`
- Minor: duplicate debug log between middleware and publisher

## Findings

| # | Severity | Finding | Effort |
|---|----------|---------|--------|
| 001 | P1 | Orphaned `src/Serializer/MessageNameStamp.php` — dead file not deleted | Small |
| 002 | P2 | `docs/solutions/test-failures/` document deleted without explanation | Small |
| 003 | P3 | Residual "bridge" terminology in variable names and comments | Small |
| 004 | P3 | Duplicate debug logging between middleware and publisher | Small |
