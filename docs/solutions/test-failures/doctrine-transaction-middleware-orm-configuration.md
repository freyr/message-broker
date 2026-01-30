---
title: "Doctrine Transaction Middleware Requires Proper ORM Configuration"
category: test-failures
tags: [doctrine, symfony-messenger, orm, dbal, transactions, middleware, lazy-ghost, php-8.4]
component: Symfony Messenger
symptom: "Tests failing with 'Doctrine ORM Manager does not exist' or 'Symfony LazyGhost is not available' errors after adding doctrine_transaction middleware"
root_cause: "doctrine_transaction middleware requires Doctrine ORM to be properly configured, even when using DBAL only. Additionally, symfony/var-exporter 8.0 removed LazyGhost support, requiring PHP 8.4 native lazy objects."
severity: high
date: 2026-01-30
---

# Doctrine Transaction Middleware Requires Proper ORM Configuration

## Problem Summary

When adding `doctrine_transaction` middleware to Symfony Messenger for transactional guarantees in tests, encountered multiple cascading errors:

1. **Initial Error**: `Doctrine ORM Manager named "" does not exist`
2. **After minimal ORM config**: `Symfony LazyGhost is not available. Please install symfony/var-exporter 6.4 or 7`
3. **After further investigation**: DeduplicationMiddleware not running despite being tagged with `messenger.middleware`

## Symptoms

```
# Error 1: Missing ORM Manager
InvalidArgumentException: Doctrine ORM Manager named "" does not exist

# Error 2: LazyGhost unavailable (with symfony/var-exporter 8.0.0 installed)
Doctrine\ORM\ORMInvalidArgumentException: Symfony LazyGhost is not available.
Please install the "symfony/var-exporter" package version 6.4 or 7 to use this
feature or enable PHP 8.4 native lazy objects.

# Error 3: Tests pass but deduplication not working
Expected deduplication entry for message ID xxx but none found
Failed asserting that 0 is greater than 0.
```

## Root Cause Analysis

### Issue 1: doctrine_transaction Requires ORM

The `doctrine_transaction` middleware **requires Doctrine ORM** to be configured, even if your application only uses Doctrine DBAL. This is because the middleware uses `EntityManagerInterface` internally.

**Why this matters**: Most developers assume DBAL-only projects don't need ORM configuration, but Symfony Messenger's transaction middleware breaks this assumption.

### Issue 2: Symfony 8.0 LazyGhost Removal

symfony/var-exporter 8.0 **removed the `ProxyHelper::generateLazyGhost()` method** that Doctrine ORM < 4.0 relies on. The error message suggests installing version 6.4 or 7, but doesn't mention that **PHP 8.4 has native lazy object support** that should be used instead.

**Version incompatibility**:
- symfony/var-exporter 8.0.0 - removed LazyGhost
- Doctrine ORM 3.6.1 - still checks for LazyGhost by default
- PHP 8.4.17 - has native lazy object support

### Issue 3: Middleware Tag vs Explicit Configuration

Middleware tagged with `messenger.middleware` is NOT automatically added to bus middleware stacks. The tag only makes the middleware **available** to be referenced, but it must still be **explicitly listed** in the bus configuration.

**This is by design**: Symfony Messenger requires explicit middleware ordering to ensure correct priority/execution order.

## Investigation Steps

### Step 1: Verified Transaction Problem Exists

Created demonstration script showing the core issue:

```php
// WITHOUT transaction (auto-commit)
$connection->insert('message_broker_deduplication', [...]);
throw new \RuntimeException('Handler failed!');
// Result: INSERT committed → message lost on retry ❌

// WITH transaction
$connection->beginTransaction();
$connection->insert('message_broker_deduplication', [...]);
throw new \RuntimeException('Handler failed!');
$connection->rollBack();
// Result: INSERT rolled back → message can retry ✓
```

**Proof**: Transaction rollback is **critical** for data integrity - without it, failed messages are permanently marked as duplicates.

### Step 2: Tried Minimal ORM Configuration (Failed)

```yaml
# tests/Functional/config/test.yaml
doctrine:
    orm:
        auto_generate_proxy_classes: false
        naming_strategy: doctrine.orm.naming_strategy.underscore_number_aware
        auto_mapping: false
```

**Result**: `Doctrine ORM Manager named "" does not exist`

**Why it failed**: Configuration was too minimal - ORM requires mappings to initialize properly.

### Step 3: Added Complete ORM Configuration (LazyGhost Error)

```yaml
doctrine:
    orm:
        auto_generate_proxy_classes: true
        enable_lazy_ghost_objects: true  # ❌ This fails!
        naming_strategy: doctrine.orm.naming_strategy.underscore_number_aware
        auto_mapping: true
        mappings:
            FreyrMessageBroker:
                is_bundle: false
                type: attribute
                dir: '%kernel.project_dir%/src/Entity'
                prefix: 'Freyr\MessageBroker\Entity'
                alias: FreyrMessageBroker
```

**Result**: `Symfony LazyGhost is not available`

**Why it failed**: symfony/var-exporter 8.0 removed `ProxyHelper::generateLazyGhost()` method.

### Step 4: Used PHP 8.4 Native Lazy Objects (Success!)

```yaml
doctrine:
    orm:
        enable_native_lazy_objects: true  # ✅ Use PHP 8.4 native support
        report_fields_where_declared: true
        naming_strategy: doctrine.orm.naming_strategy.underscore_number_aware
        auto_mapping: true
        mappings:
            FreyrMessageBroker:
                is_bundle: false
                type: attribute
                dir: '%kernel.project_dir%/src/Entity'
                prefix: 'Freyr\MessageBroker\Entity'
                alias: FreyrMessageBroker
```

**Result**: ORM initializes successfully, but tests still fail (no dedup entries).

### Step 5: Discovered Middleware Not Running

Ran existing `InboxFlowTest.php` deduplication tests:

```bash
$ phpunit tests/Functional/InboxFlowTest.php --testdox
✘ Message consumed from amqp and handled
  Expected deduplication entry for message ID xxx but none found
✘ Duplicate message is not processed twice
  Expected deduplication entry for message ID xxx but none found
```

**Key insight**: The **existing working tests** were now failing! This proved our configuration change broke deduplication entirely.

### Step 6: Checked Git Diff

```diff
 buses:
     messenger.bus.default:
-        default_middleware: true
-        middleware:
-            - 'Freyr\MessageBroker\Inbox\DeduplicationMiddleware'
+        default_middleware:
+            enabled: true
+            allow_no_handlers: false
+        middleware:
+            - doctrine_transaction
+            # DeduplicationMiddleware (priority -10) registered via service tag
```

**FOUND IT**: We removed `DeduplicationMiddleware` from the explicit middleware list, assuming the service tag would auto-add it.

**This assumption was wrong**: The `messenger.middleware` tag does NOT automatically add middleware to bus stacks.

## Complete Solution

### 1. Configure Doctrine ORM with PHP 8.4 Native Lazy Objects

**File**: `tests/Functional/config/test.yaml`

```yaml
doctrine:
    dbal:
        url: '%env(DATABASE_URL)%'
        driver: 'pdo_mysql'
        charset: utf8mb4
        default_table_options:
            charset: utf8mb4
            collate: utf8mb4_unicode_ci
        types:
            id_binary: Freyr\MessageBroker\Doctrine\Type\IdType
        mapping_types:
            binary: id_binary

    # Standard ORM configuration (required by doctrine_transaction middleware)
    # Even though we don't use entities, ORM must be properly configured
    orm:
        # CRITICAL: Use PHP 8.4 native lazy objects
        # symfony/var-exporter 8.0 removed LazyGhost support
        enable_native_lazy_objects: true   # PHP 8.4+ native lazy objects
        report_fields_where_declared: true # Standard Symfony setting
        naming_strategy: doctrine.orm.naming_strategy.underscore_number_aware
        auto_mapping: true
        mappings:
            # Empty mappings - we use DBAL only, but ORM config must be complete
            FreyrMessageBroker:
                is_bundle: false
                type: attribute
                dir: '%kernel.project_dir%/src/Entity'  # Create empty dir
                prefix: 'Freyr\MessageBroker\Entity'
                alias: FreyrMessageBroker
```

**Note**: The `src/Entity` directory must exist (even if empty):

```bash
mkdir -p src/Entity
touch src/Entity/.gitkeep
```

### 2. Explicitly Add All Middleware to Bus Configuration

```yaml
framework:
    messenger:
        failure_transport: failed

        buses:
            messenger.bus.default:
                # CRITICAL: Both settings required
                default_middleware:
                    enabled: true
                    allow_no_handlers: false

                middleware:
                    # CRITICAL: Explicit ordering matters!
                    - doctrine_transaction  # Priority 0 (starts transaction)
                    - 'Freyr\MessageBroker\Inbox\DeduplicationMiddleware'  # Priority -10
```

**Why explicit listing is required**:
- The `messenger.middleware` service tag does NOT auto-add middleware to buses
- The tag only makes middleware **available** for reference
- Explicit listing ensures correct execution order
- `doctrine_transaction` must run BEFORE `DeduplicationMiddleware`

### 3. Keep Middleware Service Tagged (for DI)

**File**: `tests/Functional/config/test.yaml` (services section)

```yaml
services:
    # Deduplication Middleware
    # Tag is for service discovery, NOT automatic bus registration
    Freyr\MessageBroker\Inbox\DeduplicationMiddleware:
        arguments:
            $store: '@Freyr\MessageBroker\Inbox\DeduplicationStore'
        tags:
            - { name: 'messenger.middleware', priority: -10 }
```

**Important**: Keep the tag - it's needed for the service container, but it doesn't automatically add the middleware to buses.

## Verification

### Test 1: Transaction Rollback Works

```bash
$ phpunit tests/Functional/TransactionBehaviorTest.php
✓ Verify actual transaction behavior
  Transaction rollback IS working! Dedup entry was rolled back when handler threw.
```

### Test 2: Existing Deduplication Tests Pass

```bash
$ phpunit tests/Functional/InboxFlowTest.php --testdox
✔ Message consumed from amqp and handled
✔ Duplicate message is not processed twice
✔ Semantic name translation
✔ Message format correctness

OK (4 tests, 20 assertions)
```

### Test 3: New Transaction Rollback Tests Pass

```bash
$ phpunit tests/Functional/InboxTransactionRollbackTest.php --testdox
✔ Handler exception rolls back deduplication entry
✔ Handler succeeds after retry
✔ Multiple handler exceptions in sequence

OK (3 tests, 18 assertions)
```

## Why This Matters

### Data Integrity Impact

**Without `doctrine_transaction` middleware**:
1. Message arrives with `messageId: abc-123`
2. DeduplicationMiddleware inserts record (auto-committed immediately)
3. Handler throws exception
4. Message retry → "Duplicate! Skip handler"
5. **Handler never runs again = DATA LOSS**

**With `doctrine_transaction` middleware**:
1. Transaction starts
2. Message arrives with `messageId: abc-123`
3. DeduplicationMiddleware inserts record (within transaction)
4. Handler throws exception
5. Transaction rolls back → dedup entry removed
6. Message retry → "New message! Process it"
7. **Handler runs again = EVENTUAL SUCCESS**

### Why Standard Symfony Projects "Just Work"

Most Symfony projects install `doctrine/doctrine-bundle` with ORM configured by default (via Symfony Flex recipes). DBAL-only projects are the exception, not the norm.

**This package is DBAL-only** by design (no entities), which is why we hit this issue.

## Prevention Strategies

### 1. Document ORM Requirement

**File**: `CLAUDE.md` (updated)

```markdown
## Configuration Requirements

### Doctrine ORM Configuration (Required for Tests)

Even though this package uses DBAL only in production, **tests require Doctrine ORM**
because `doctrine_transaction` middleware depends on `EntityManagerInterface`.

**Minimum test configuration**:
```yaml
doctrine:
    orm:
        enable_native_lazy_objects: true  # PHP 8.4+
        auto_mapping: true
        mappings:
            YourApp:
                is_bundle: false
                type: attribute
                dir: '%kernel.project_dir%/src/Entity'
```
```

### 2. Add Configuration Validation Test

Create a test that fails fast if ORM is misconfigured:

```php
final class ConfigurationValidationTest extends KernelTestCase
{
    public function testDoctrineOrmIsConfigured(): void
    {
        self::bootKernel();

        // Verify ORM EntityManager is available
        $this->assertTrue(
            self::getContainer()->has('doctrine.orm.default_entity_manager'),
            'Doctrine ORM must be configured for doctrine_transaction middleware'
        );
    }

    public function testDeduplicationMiddlewareIsRegistered(): void
    {
        self::bootKernel();

        $middlewareStack = self::getContainer()
            ->get('messenger.bus.default')
            ->getMiddlewareStack();

        $hasDedup = false;
        foreach ($middlewareStack as $middleware) {
            if ($middleware instanceof DeduplicationMiddleware) {
                $hasDedup = true;
                break;
            }
        }

        $this->assertTrue(
            $hasDedup,
            'DeduplicationMiddleware must be explicitly added to bus middleware list'
        );
    }
}
```

### 3. Version Constraints in composer.json

```json
{
    "require-dev": {
        "doctrine/orm": "^3.6",
        "symfony/var-exporter": "^6.4|^7.0|^8.0"
    },
    "config": {
        "platform": {
            "php": "8.4.17"
        }
    }
}
```

**Note**: symfony/var-exporter 8.0 is fine if using `enable_native_lazy_objects: true`.

## Related Issues

- See: `docs/brainstorms/2026-01-30-edge-case-failure-mode-test-scenarios.md` - Original test planning
- See: `docs/plans/2026-01-30-test-phase-1-critical-data-integrity-tests-plan.md` - Implementation plan
- Related: GitHub Issue #5 - Implement Phase 1 critical data integrity tests

## Key Takeaways

1. **`doctrine_transaction` requires ORM** - Even in DBAL-only projects, configure ORM for test environments
2. **PHP 8.4 native lazy objects** - Use `enable_native_lazy_objects: true` instead of `enable_lazy_ghost_objects`
3. **symfony/var-exporter 8.0 compatibility** - Removed LazyGhost support, use native PHP 8.4 feature
4. **Middleware tags don't auto-add to buses** - Always explicitly list middleware in bus configuration
5. **Middleware ordering matters** - `doctrine_transaction` must run before `DeduplicationMiddleware`
6. **Empty entity directory is fine** - ORM requires mappings config even if no entities exist

## Time Investment

- **Initial investigation**: ~2 hours
- **Solution implementation**: ~30 minutes
- **Verification**: ~15 minutes
- **Documentation**: ~20 minutes

**Total**: ~3 hours

**Future benefit**: Next occurrence will take < 5 minutes by referencing this document.
