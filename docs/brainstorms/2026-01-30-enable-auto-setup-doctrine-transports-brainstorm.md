---
title: Enable auto_setup for Doctrine Messenger Transports
type: refactor
date: 2026-01-30
status: ready-for-planning
---

# Enable auto_setup for Doctrine Messenger Transports

## Context

The package currently uses `auto_setup: false` for all Messenger transports (outbox, failed, AMQP). This was originally chosen to allow for custom outbox table structure. However, the outbox table (`messenger_outbox`) has evolved to be identical to Symfony Messenger's standard schema, using `BIGINT AUTO_INCREMENT` for the ID column.

**Current Pain Points:**
- Manual migration maintenance is tedious and error-prone (recent CI workflow failure due to schema.sql vs actual schema mismatch)
- Deployment friction requiring schema coordination before code deployment
- No actual customizations in the outbox table to justify manual management

**Current Benefits to Preserve:**
- 3-table architecture provides monitoring/observability (separate outbox from failed messages)
- Performance isolation (no contention between high-throughput outbox and failed message handling)

## What We're Building

Refactor the Messenger configuration to enable `auto_setup: true` for Doctrine transports (outbox, failed) while maintaining the 3-table architecture and keeping AMQP transports with `auto_setup: false`.

**Tables after change:**
1. `messenger_outbox` - Symfony-managed (auto_setup)
2. `messenger_messages` - Symfony-managed (auto_setup)
3. `message_broker_deduplication` - Application-managed (manual migration)

**AMQP infrastructure remains manually managed** (exchanges, queues, bindings).

## Why This Approach

### Chosen: Approach 1 - Enable auto_setup for Doctrine transports

**Rationale:**
- Eliminates manual sync issues between migration files and actual schema
- Symfony handles schema evolution automatically (future-proof for Messenger updates)
- Preserves monitoring/performance benefits (separate tables for different concerns)
- Reduces deployment friction (no pre-deployment schema coordination)
- Standard Symfony Messenger pattern (auto_setup is the default)

**Trade-offs accepted:**
- Symfony creates tables on first worker run (instead of explicit migration step)
- Less control over index optimization (uses Symfony's battle-tested defaults)
- Must trust Symfony's schema management (acceptable for mature framework)

### Alternatives Considered

**Approach 2: Single table consolidation**
- Use one `messenger_messages` table for both outbox and failed (differentiated by `queue_name`)
- **Rejected:** Loses monitoring visibility and performance isolation, which are stated priorities

**Approach 3: Hybrid auto/manual**
- Enable auto_setup only for failed transport, keep manual for outbox
- **Rejected:** Mixed approach adds confusion, outbox throughput doesn't justify extra management burden

**Approach 4: Keep current setup**
- Continue with `auto_setup: false` and manual migrations
- **Rejected:** Doesn't address deployment friction and maintenance pain points

## Key Decisions

### 1. Enable auto_setup for Doctrine Transports Only

**Decision:** Set `auto_setup: true` for:
- Outbox transport (`doctrine://default?table_name=messenger_outbox&queue_name=outbox`)
- Failed transport (`doctrine://default?queue_name=failed`)

**Decision:** Keep `auto_setup: false` for:
- All AMQP transports (infrastructure managed separately by ops/IaC)
- Message broker deduplication (custom application table)

**Reason:** AMQP infrastructure (exchanges, queues, bindings) should be managed by operations team or infrastructure-as-code. Deduplication table has custom schema requirements (binary UUID v7 primary key).

### 2. Remove migrations/schema.sql for Messenger Tables

**Decision:** Delete `messenger_outbox` and `messenger_messages` table definitions from `migrations/schema.sql`, keep only `message_broker_deduplication`.

**Reason:** With auto_setup enabled, Symfony manages these tables. Having stale migration files creates confusion and potential for drift.

### 3. Update Documentation to Reflect Auto-Setup

**Decision:** Update `docs/database-schema.md`, `README.md`, and `CLAUDE.md` to document:
- Which tables are auto-managed vs manual
- First-run behavior (tables created by Symfony on first worker start)
- Deduplication table still requires manual migration

**Reason:** Clear documentation prevents confusion and ensures team knows which tables they manage vs framework manages.

### 4. Preserve 3-Table Architecture

**Decision:** Keep separate `messenger_outbox` and `messenger_messages` tables (via `table_name` parameter).

**Reason:** Operational benefits (monitoring, performance isolation) outweigh simplicity gains of single table. This was a stated priority during brainstorming.

## Implementation Scope

### In Scope
1. Update `recipe/1.0/config/packages/messenger.yaml` to set `auto_setup: true` for doctrine transports
2. Update `tests/Functional/config/test.yaml` similarly
3. Remove messenger table definitions from `migrations/schema.sql` (keep deduplication table)
4. Update `docs/database-schema.md` to reflect auto-managed vs manual tables
5. Update `README.md` and `CLAUDE.md` with new setup instructions
6. Test that tables are created automatically on first worker run
7. Verify functional tests still pass with auto-created tables

### Out of Scope
- Changing AMQP transport configuration (stays `auto_setup: false`)
- Modifying deduplication table schema or management
- Custom index optimization (use Symfony defaults initially, optimize later if needed)
- Migration path for existing installations (breaking change acceptable for pre-1.0 package)

## Success Criteria

1. ✅ Doctrine transports have `auto_setup: true` in all config files
2. ✅ `migrations/schema.sql` contains only `message_broker_deduplication` table
3. ✅ Tables are automatically created on first `messenger:consume` command
4. ✅ Functional tests pass without manual schema setup
5. ✅ Documentation clearly explains auto-managed vs manual tables
6. ✅ CI workflow no longer needs to run `migrations/schema.sql` for messenger tables
7. ✅ 3-table architecture preserved (separate monitoring/performance)

## Open Questions

1. **Backward compatibility:** Should we provide a migration guide for existing users?
   - **Answer:** Not critical for pre-1.0 package, but could add a CHANGELOG entry

2. **CI workflow:** Can we simplify `.github/workflows/tests.yml` to remove messenger table setup?
   - **Answer:** Yes - only need to create deduplication table in workflow

3. **First-run behavior:** Should documentation warn that tables are created on first worker start?
   - **Answer:** Yes - add explicit note in README and CLAUDE.md about first-run table creation

## Dependencies

- None - this is purely a configuration change
- Requires functional tests to validate (already exist)

## Risks and Mitigations

### Risk: Unexpected table creation in production
**Impact:** Medium - Operations team might be surprised by automatic table creation
**Mitigation:** Clear documentation, CHANGELOG entry, consider release notes

### Risk: Symfony's default schema doesn't meet needs
**Impact:** Low - Symfony's schema is battle-tested and production-ready
**Mitigation:** Can add custom indexes later via Doctrine migrations if needed

### Risk: Breaking change for existing users
**Impact:** Low - Package is pre-1.0, users expect breaking changes
**Mitigation:** Document in CHANGELOG, provide clear upgrade path

## Next Steps

1. Run `/workflows:plan` to create detailed implementation plan
2. Update configuration files (messenger.yaml)
3. Clean up migration files
4. Update documentation
5. Test in both local and CI environments
6. Document breaking change in CHANGELOG
