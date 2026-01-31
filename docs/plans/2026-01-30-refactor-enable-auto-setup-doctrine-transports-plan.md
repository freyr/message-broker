---
title: refactor: Enable auto_setup for Doctrine Messenger transports
type: refactor
date: 2026-01-30
---

# Enable auto_setup for Doctrine Messenger Transports

## Overview

Refactor Messenger configuration to enable `auto_setup: true` for Doctrine transports (outbox, failed) while maintaining the 3-table architecture and keeping AMQP transports manually managed. This eliminates manual migration maintenance burden and reduces deployment friction.

**Brainstorm Reference:** `docs/brainstorms/2026-01-30-enable-auto-setup-doctrine-transports-brainstorm.md`

## Problem Statement / Motivation

**Current Pain Points:**
1. **Manual migration maintenance is tedious and error-prone**
   - Recent CI failure: `migrations/schema.sql` had incorrect schema (BINARY(16)) vs actual requirements (BIGINT AUTO_INCREMENT)
   - Documented in `docs/solutions/database-issues/migration-schema-mismatch-ci-vs-local.md`
   - Requires vigilance to keep migration files in sync with Symfony Messenger requirements

2. **Deployment friction**
   - Schema must exist before deploying code (pre-deployment coordination)
   - CI workflow must manually execute `migrations/schema.sql` before running tests
   - Increases complexity of deployment process

3. **No customizations justify manual management**
   - `messenger_outbox` table is identical to Symfony's default schema
   - `messenger_messages` table is identical to Symfony's default schema
   - Only `message_broker_deduplication` has custom requirements (binary UUID v7 PK)

**Why This Matters:**
- Reduces operational burden (fewer things to manually manage)
- Prevents schema drift and configuration errors
- Aligns with Symfony Messenger best practices (auto_setup is the default)
- Preserves monitoring/performance benefits (3-table architecture remains)

## Proposed Solution

Enable `auto_setup: true` for Doctrine-based transports (outbox, failed), allowing Symfony Messenger to automatically create and manage these tables on first worker start.

**Tables After Change:**
1. **`messenger_outbox`** - Symfony-managed (auto_setup enabled)
2. **`messenger_messages`** - Symfony-managed (auto_setup enabled)
3. **`message_broker_deduplication`** - Application-managed (manual migration)

**AMQP Configuration:**
- Keep `auto_setup: false` (infrastructure managed by operations/IaC)

**Key Changes:**
1. Update configuration files to set `auto_setup: true` for Doctrine transports
2. Remove `messenger_outbox` and `messenger_messages` from `migrations/schema.sql`
3. Simplify CI workflow to only create deduplication table
4. Update documentation to reflect auto-managed vs manual tables

## Technical Considerations

### Architecture Impacts

**3-Table Architecture Preserved:**
- Separate `messenger_outbox` and `messenger_messages` tables maintained
- Configuration uses `table_name` parameter to distinguish tables
- Monitoring and performance isolation benefits remain unchanged

**First-Run Behaviour:**
- Tables created automatically on first `messenger:consume` or `messenger:consume outbox` command
- Symfony uses `CREATE TABLE IF NOT EXISTS` (idempotent)
- No risk of duplicate table creation

**Backward Compatibility:**
- **Breaking change** for existing installations (tables must exist before enabling auto_setup)
- Acceptable for pre-1.0 package
- Mitigation: Document upgrade path in CHANGELOG

### Performance Implications

**Minimal Impact:**
- Auto_setup only affects first run (table creation)
- Subsequent runs have identical performance (no schema checks)
- Symfony caches metadata about table existence

**3-Table Architecture Benefits:**
- No contention between outbox publishing and failed message handling
- Independent index optimization per table
- Separate monitoring/observability per queue

### Security Considerations

**Low Risk:**
- Symfony Messenger's default schema is production-tested
- No additional permissions required (same as manual CREATE TABLE)
- Application database user already needs CREATE TABLE permission for migrations

### Dependency Analysis

**No External Dependencies:**
- Pure configuration change
- Uses existing Symfony Messenger 7.3+ features
- Functional tests already exist to validate behaviour

## Acceptance Criteria

### Configuration Updates
- [x] `recipe/1.0/config/packages/messenger.yaml` has `options: { auto_setup: true }` for outbox transport
- [x] `recipe/1.0/config/packages/messenger.yaml` has `options: { auto_setup: true }` for failed transport
- [x] `tests/Functional/config/test.yaml` has `options: { auto_setup: true }` for outbox transport
- [x] `tests/Functional/config/test.yaml` has `options: { auto_setup: true }` for failed transport
- [x] All AMQP transports retain `auto_setup: false` (no change)

### Schema Cleanup
- [x] `migrations/schema.sql` contains only `message_broker_deduplication` table definition
- [x] `messenger_outbox` table definition removed from schema.sql
- [x] `messenger_messages` table definition removed from schema.sql
- [x] Recipe migration (`recipe/1.0/migrations/Version20250103000001.php`) unchanged (only creates deduplication table)

### CI/CD Updates
- [x] `.github/workflows/tests.yml` schema setup step only loads deduplication table
- [x] CI workflow still executes `migrations/schema.sql` (but with reduced content)
- [ ] Tests pass with auto-created messenger tables

### Functional Testing
- [x] Outbox flow tests pass (`tests/Functional/OutboxFlowTest.php`)
- [x] Inbox flow tests pass (`tests/Functional/InboxFlowTest.php`)
- [x] Tables are created automatically when tests run (no manual setup)
- [x] 3-table architecture verified (messenger_outbox auto-created with BIGINT AUTO_INCREMENT)

### Documentation Updates
- [x] `docs/database-schema.md` updated to explain auto-managed vs manual tables
- [x] `docs/database-schema.md` notes first-run table creation behaviour
- [x] `README.md` setup instructions updated (remove manual messenger table creation)
- [x] `CLAUDE.md` configuration reference updated (auto_setup: true)
- [x] CHANGELOG.md entry created explaining breaking change

## Success Metrics

**Operational Metrics:**
- Zero manual messenger table management required (down from 2 tables)
- CI workflow simplified (one table instead of three in schema.sql)
- Deployment friction reduced (no pre-deployment schema coordination)

**Quality Metrics:**
- All functional tests pass without manual schema setup
- 3-table architecture preserved (monitoring/performance benefits)
- Documentation clarity (team understands auto vs manual tables)

## Implementation Tasks

### Phase 1: Configuration Updates

**Task 1.1: Update Recipe Configuration**
- File: `recipe/1.0/config/packages/messenger.yaml`
- Change outbox transport to add `options: { auto_setup: true }`
- Change failed transport to add `options: { auto_setup: true }`
- Keep AMQP transport with `auto_setup: false`

```yaml
# recipe/1.0/config/packages/messenger.yaml

framework:
  messenger:
    transports:
      # Outbox transport - AUTO-MANAGED
      outbox:
        dsn: 'doctrine://default?table_name=messenger_outbox&queue_name=outbox'
        options:
          auto_setup: true  # ADD THIS
        retry_strategy:
          max_retries: 3
          delay: 1000
          multiplier: 2

      # AMQP publish transport - MANUAL MANAGEMENT
      amqp:
        dsn: '%env(MESSENGER_AMQP_DSN)%'
        serializer: 'Freyr\MessageBroker\Serializer\OutboxSerializer'
        options:
          auto_setup: false  # KEEP FALSE
        retry_strategy:
          max_retries: 3
          delay: 1000
          multiplier: 2

      # Failed transport - AUTO-MANAGED
      failed:
        dsn: 'doctrine://default?queue_name=failed'
        options:
          auto_setup: true  # ADD THIS
```

**Task 1.2: Update Test Configuration**
- File: `tests/Functional/config/test.yaml`
- Change outbox transport to add `options: { auto_setup: true }`
- Change failed transport to add `options: { auto_setup: true }`
- Keep AMQP test transport with `auto_setup: false`

```yaml
# tests/Functional/config/test.yaml

framework:
  messenger:
    transports:
      # Outbox transport - AUTO-MANAGED
      outbox:
        dsn: 'doctrine://default?table_name=messenger_outbox&queue_name=outbox'
        serializer: 'Freyr\MessageBroker\Serializer\OutboxSerializer'
        options:
          auto_setup: true  # ADD THIS

      # AMQP transports - MANUAL MANAGEMENT
      amqp_test:
        dsn: '%env(MESSENGER_AMQP_DSN)%'
        serializer: 'Freyr\MessageBroker\Serializer\InboxSerializer'
        options:
          auto_setup: false  # KEEP FALSE
          queue:
            name: 'test_inbox'

      # Failed transport - AUTO-MANAGED
      failed:
        dsn: 'doctrine://default?queue_name=failed'
        options:
          auto_setup: true  # ADD THIS
```

### Phase 2: Schema Cleanup

**Task 2.1: Clean Up migrations/schema.sql**
- File: `migrations/schema.sql`
- Remove `messenger_outbox` table definition (lines 12-23)
- Remove `messenger_messages` table definition (lines 37-48)
- Keep only `message_broker_deduplication` table definition (lines 27-33)

```sql
-- migrations/schema.sql (AFTER cleanup)

-- Message Broker Deduplication Table
-- Used by DeduplicationMiddleware for inbox idempotency
-- Custom application table (not managed by Symfony Messenger)
CREATE TABLE IF NOT EXISTS message_broker_deduplication (
    message_id BINARY(16) NOT NULL PRIMARY KEY COMMENT '(DC2Type:id_binary)',
    message_name VARCHAR(255) NOT NULL,
    processed_at DATETIME NOT NULL,
    INDEX idx_message_name (message_name),
    INDEX idx_processed_at (processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note: messenger_outbox and messenger_messages tables are now auto-managed by Symfony Messenger
-- They will be created automatically on first worker run (auto_setup: true)
```

**Task 2.2: Verify Recipe Migration Unchanged**
- File: `recipe/1.0/migrations/Version20250103000001.php`
- Verify it only creates `message_broker_deduplication` table (no change needed)
- This migration is correct (only application-managed table)

### Phase 3: CI/CD Simplification

**Task 3.1: Update CI Workflow**
- File: `.github/workflows/tests.yml`
- Schema setup step remains, but now only creates deduplication table
- Comment explaining auto-managed tables

```yaml
# .github/workflows/tests.yml

- name: Setup database schema
  run: |
    # Only create deduplication table (messenger tables are auto-managed)
    mysql -h 127.0.0.1 -P 3308 -u messenger -pmessenger messenger_test < migrations/schema.sql
  continue-on-error: true
```

**Note:** The workflow command doesn't change, but `schema.sql` content is reduced. Add comment for clarity.

### Phase 4: Documentation Updates

**Task 4.1: Update docs/database-schema.md**
- File: `docs/database-schema.md`
- Add section explaining auto-managed vs manual tables
- Document first-run table creation behaviour
- Update schema examples to note auto-setup

```markdown
<!-- Add to top of Database Schema Architecture section -->

## Table Management Strategy

The package uses a **mixed management strategy** for database tables:

### Auto-Managed Tables (Symfony Messenger)
These tables are created and managed automatically by Symfony Messenger when `auto_setup: true`:

1. **`messenger_outbox`** - Outbox pattern transport table
   - Created on first `messenger:consume outbox` command
   - Standard Symfony Messenger Doctrine transport schema
   - Uses `table_name=messenger_outbox` parameter

2. **`messenger_messages`** - Failed messages transport table
   - Created on first `messenger:consume` command (any transport)
   - Standard Symfony Messenger Doctrine transport schema
   - Uses default table name

**First-Run Behaviour:**
- Symfony uses `CREATE TABLE IF NOT EXISTS` (idempotent)
- Tables are created when the worker first polls the transport
- No manual migration or schema.sql setup required

### Application-Managed Tables (Manual Migration)
These tables require manual migration (custom schema requirements):

1. **`message_broker_deduplication`** - Inbox deduplication tracking
   - Created via `migrations/schema.sql` or recipe migration
   - Custom schema: `BINARY(16)` UUID v7 primary key
   - Required before running inbox consumers
```

**Task 4.2: Update README.md**
- File: `README.md`
- Update "Configuration Requirements" section
- Document auto-managed tables
- Simplify setup instructions

```markdown
<!-- Update Database Schema section in README.md -->

### Database Schema - 3-Table Architecture

The package uses a **3-table approach** with mixed management:

**Auto-Managed by Symfony (no manual setup needed):**
1. **`messenger_outbox`** - Dedicated outbox table for publishing events
2. **`messenger_messages`** - Standard table for failed messages

**Application-Managed (manual setup required):**
3. **`message_broker_deduplication`** - Deduplication tracking (binary UUID v7 PK)

**Setup Instructions:**

```bash
# 1. Create deduplication table only (messenger tables auto-created)
mysql -u user -p database_name < migrations/schema.sql

# 2. Start workers - messenger tables will be created automatically
php bin/console messenger:consume outbox -vv
```

**First-Run Note:** The messenger_outbox and messenger_messages tables will be automatically created when you first start the worker. This is expected behaviour with `auto_setup: true`.

See `docs/database-schema.md` for complete schema details and rationale.
```

**Task 4.3: Update CLAUDE.md**
- File: `CLAUDE.md`
- Update "Configuration Requirements" section
- Update "Database Schema Requirements" section
- Note auto-setup for Doctrine transports

```markdown
<!-- Update in CLAUDE.md - Configuration Requirements section -->

### Messenger Configuration (messenger.yaml)
The package requires specific messenger transport configuration:

```yaml
framework:
  messenger:
    transports:
      # Outbox transport - AUTO-MANAGED (auto_setup: true)
      outbox:
        dsn: 'doctrine://default?table_name=messenger_outbox&queue_name=outbox'
        options:
          auto_setup: true  # Symfony creates table automatically
        retry_strategy:
          max_retries: 3
          delay: 1000
          multiplier: 2

      # AMQP publish transport - MANUAL MANAGEMENT (auto_setup: false)
      amqp:
        dsn: '%env(MESSENGER_AMQP_DSN)%'
        serializer: 'Freyr\MessageBroker\Serializer\OutboxSerializer'
        options:
          auto_setup: false  # Infrastructure managed by ops
        retry_strategy:
          max_retries: 3

      # Failed transport - AUTO-MANAGED (auto_setup: true)
      failed:
        dsn: 'doctrine://default?queue_name=failed'
        options:
          auto_setup: true  # Symfony creates table automatically
```

**Auto-Setup Policy:**
- **Doctrine transports (outbox, failed)**: `auto_setup: true` - Symfony manages tables
- **AMQP transports**: `auto_setup: false` - Infrastructure managed separately
- **Deduplication table**: Manual migration required (custom schema)
```

**Task 4.4: Create CHANGELOG Entry**
- File: `CHANGELOG.md`
- Add breaking change entry for v0.3.0 (or next version)

```markdown
## [Unreleased]

### Changed
- **BREAKING**: Enabled `auto_setup: true` for Doctrine Messenger transports (outbox, failed)
  - `messenger_outbox` and `messenger_messages` tables are now auto-created by Symfony
  - `migrations/schema.sql` reduced to only `message_broker_deduplication` table
  - First worker run will create messenger tables automatically
  - **Migration path**: Existing installations must have messenger tables created before upgrading
  - **Benefit**: Eliminates manual migration maintenance, reduces deployment friction

### Removed
- Manual messenger table definitions from `migrations/schema.sql` (auto-managed by Symfony)
```

### Phase 5: Testing and Validation

**Task 5.1: Local Testing**
- Drop and recreate test database to verify auto-setup works
- Run functional tests to ensure tables are created automatically
- Verify 3-table architecture is preserved

```bash
# Local testing commands

# 1. Drop test database (clean slate)
docker compose exec mysql mysql -u root -proot -e "DROP DATABASE IF EXISTS messenger_test; CREATE DATABASE messenger_test;"

# 2. Create only deduplication table
docker compose exec mysql mysql -u messenger -pmessenger messenger_test < migrations/schema.sql

# 3. Run functional tests - messenger tables should be auto-created
docker compose run --rm php vendor/bin/phpunit tests/Functional/ --testdox

# 4. Verify 3 tables exist
docker compose exec mysql mysql -u messenger -pmessenger messenger_test -e "SHOW TABLES;"

# Expected output:
# +----------------------------------+
# | Tables_in_messenger_test         |
# +----------------------------------+
# | message_broker_deduplication     |
# | messenger_messages               |
# | messenger_outbox                 |
# +----------------------------------+
```

**Task 5.2: CI Validation**
- Push changes to feature branch
- Verify GitHub Actions workflow passes
- Check workflow logs to confirm auto-table-creation

**Task 5.3: Verify Table Schemas**
- After tests run, inspect auto-created table schemas
- Confirm they match Symfony Messenger defaults
- Verify indexes are created correctly

```bash
# Inspect auto-created table schemas
docker compose exec mysql mysql -u messenger -pmessenger messenger_test -e "SHOW CREATE TABLE messenger_outbox\G"
docker compose exec mysql mysql -u messenger -pmessenger messenger_test -e "SHOW CREATE TABLE messenger_messages\G"

# Expected: BIGINT AUTO_INCREMENT PK, standard Symfony columns/indexes
```

## Dependencies & Risks

### Dependencies
- **None** - Pure configuration change
- Symfony Messenger 7.3+ (already required)
- Functional tests (already exist)

### Risks and Mitigations

**Risk 1: Unexpected table creation in production**
- **Impact:** Medium - Operations team might be surprised by automatic table creation
- **Likelihood:** Medium - If not clearly communicated
- **Mitigation:**
  - Clear CHANGELOG entry with breaking change notice
  - Documentation explicitly notes first-run behaviour
  - Consider release notes or migration guide

**Risk 2: Symfony's default schema doesn't meet future needs**
- **Impact:** Low - Can add custom indexes later
- **Likelihood:** Very Low - Symfony's schema is battle-tested
- **Mitigation:**
  - Use Doctrine migrations for index-only changes if needed
  - Symfony allows customization via SQL statements

**Risk 3: Breaking change for existing users**
- **Impact:** Medium - Existing installations must have tables before upgrading
- **Likelihood:** High - This is a breaking change
- **Mitigation:**
  - Package is pre-1.0 (breaking changes expected)
  - CHANGELOG documents upgrade path
  - Consider adding upgrade guide in docs/

**Risk 4: CI workflow regression**
- **Impact:** Medium - Tests might fail if auto-setup doesn't work
- **Likelihood:** Low - Auto-setup is well-tested Symfony feature
- **Mitigation:**
  - Test locally before pushing
  - Verify CI passes on feature branch
  - Can revert if issues found

## References & Research

### Internal References
- **Brainstorm:** `docs/brainstorms/2026-01-30-enable-auto-setup-doctrine-transports-brainstorm.md`
- **Database schema docs:** `docs/database-schema.md`
- **Recent schema mismatch fix:** `docs/solutions/database-issues/migration-schema-mismatch-ci-vs-local.md`
- **Current configuration:** `recipe/1.0/config/packages/messenger.yaml`
- **Test configuration:** `tests/Functional/config/test.yaml`

### External References
- [Symfony Messenger Doctrine Transport](https://symfony.com/doc/current/messenger.html#doctrine-transport)
- [Symfony Messenger auto_setup option](https://symfony.com/doc/current/messenger.html#transport-configuration)

### Related Work
- **PR #2:** Functional tests for outbox/inbox patterns (recently merged)
- **Issue #1:** Implement functional tests (closed)
- **Commit 44a10bed:** Fixed schema.sql for messenger_outbox (BIGINT AUTO_INCREMENT)
