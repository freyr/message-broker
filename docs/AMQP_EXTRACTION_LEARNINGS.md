# Institutional Learnings for AMQP Package Extraction

**Search Date:** 2026-02-13
**Plan Reference:** `docs/plans/2026-02-13-refactor-extract-amqp-package-plan.md`
**Files Scanned:** 6 solution documents
**Relevant Learnings:** 4 critical documents apply directly

---

## Search Context

**Feature/Task:** Extract AMQP code from `freyr/message-broker` into new standalone `freyr/message-broker-amqp` package.

**Keywords Searched:**
- `middleware|registration|DI|configuration|bundle|extension`
- `services.yaml|Doctrine|schema|test.*setup|bootstrap`
- `namespace|refactor|extract|package|import|circular.*depend`

**Candidate Files Identified:** 6 documents with relevant patterns

---

## Critical Patterns - Always Check First

**File:** `/Users/michal/code/freyr/message-broker/docs/solutions/patterns/critical-patterns.md`

### Pattern 1: Test Environment Schema Setup (ALWAYS REQUIRED)

**Applies to AMQP extraction:** YES - New package will have unit tests

**Key Learning:**
> "Schema setup must happen in test bootstrap (`setUpBeforeClass()`), NOT as CI-specific steps"

**Critical Quote:**
```php
// ✅ CORRECT (Schema setup in test bootstrap)
abstract class FunctionalTestCase extends KernelTestCase
{
    private static bool $schemaInitialized = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Setup database schema once for entire test suite
        if (!self::$schemaInitialized) {
            self::setupDatabaseSchema();
            self::$schemaInitialized = true;
        }
    }
}
```

**Why This Matters for AMQP Extraction:**
- New AMQP package will have unit tests (not functional tests using DB)
- However, if future functional tests are added to AMQP package, follow this pattern
- Prevents environment parity issues between CI and local
- Avoids silent failures from masked errors

**Application to AMQP Package:**
- Unit tests (routing, topology, etc.) don't need database setup
- If AMQP functional tests are added later, implement schema setup in test bootstrap
- Document test infrastructure requirements clearly in `tests/bootstrap.php`

---

## Relevant Learnings from Solutions

### Learning 1: Doctrine Transaction Middleware Configuration

**File:** `/Users/michal/code/freyr/message-broker/docs/solutions/test-failures/doctrine-transaction-middleware-orm-configuration.md`

**Applies to AMQP extraction:** CRITICAL for middleware registration understanding

**Key Issue Discovered:**
> "Middleware tagged with `messenger.middleware` is NOT automatically added to bus middleware stacks. The tag only makes the middleware **available** to be referenced, but it must still be **explicitly listed** in the bus configuration."

**Critical Quote:**
```yaml
# ❌ WRONG - Service tag alone doesn't register middleware to buses
Freyr\MessageBroker\Inbox\DeduplicationMiddleware:
    tags:
        - { name: 'messenger.middleware', priority: -10 }

# ✅ CORRECT - Must explicitly list in bus configuration
framework:
    messenger:
        buses:
            messenger.bus.default:
                middleware:
                    - doctrine_transaction  # Priority 0
                    - 'Freyr\MessageBroker\Inbox\DeduplicationMiddleware'  # Priority -10
```

**Why This Matters for AMQP Extraction:**

The AMQP package contains **no middleware**, but this learning reveals a critical DI pattern:
- Service tags alone don't auto-register components to bus/container
- Explicit configuration is required in `FreyrMessageBrokerAmqpExtension`
- This applies directly to `message_broker.outbox_publisher` tag registration

**Application to `AmqpOutboxPublisher` Service Registration:**

Current plan shows correct pattern:
```yaml
# ✅ CORRECT - Explicit service definition with tag
Freyr\MessageBrokerAmqp\AmqpOutboxPublisher:
    arguments:
        $senderLocator: !service_locator
            amqp: '@messenger.transport.amqp'
        $routingStrategy: '@Freyr\MessageBrokerAmqp\Routing\AmqpRoutingStrategyInterface'
        $logger: '@logger'
    tags:
        - { name: 'message_broker.outbox_publisher', transport: 'outbox' }
```

**Core Compiler Pass in Main Package Must Process Tag:**

This is the **critical dependency** - the core `freyr/message-broker` package MUST have a compiler pass that:
1. Discovers services tagged with `message_broker.outbox_publisher`
2. Explicitly registers them (doesn't happen automatically)
3. See core file: `src/DependencyInjection/Compiler/OutboxPublisherPass.php`

**Document this in AMQP bundle README:** The tag alone doesn't work - core compiler pass must process it.

---

### Learning 2: Doctrine ORM Configuration for DBAL-Only Projects

**File:** `/Users/michal/code/freyr/message-broker/docs/solutions/test-failures/doctrine-transaction-middleware-orm-configuration.md`

**Applies to AMQP extraction:** CONDITIONAL - Only if AMQP package adds functional tests

**Key Issue Discovered:**
```
symfony/var-exporter 8.0 removed LazyGhost support
Doctrine ORM 3.6 still checks for LazyGhost by default
PHP 8.4 has native lazy object support that should be used instead
```

**Critical Quote:**
```yaml
# ❌ WRONG - LazyGhost removed in symfony/var-exporter 8.0
doctrine:
    orm:
        enable_lazy_ghost_objects: true

# ✅ CORRECT - Use PHP 8.4 native lazy objects
doctrine:
    orm:
        enable_native_lazy_objects: true  # PHP 8.4+
        auto_mapping: true
        mappings:
            FreyrMessageBrokerAmqp:
                is_bundle: false
                type: attribute
                dir: '%kernel.project_dir%/src/Entity'
                prefix: 'Freyr\MessageBrokerAmqp\Entity'
```

**Why This Matters for AMQP Extraction:**

AMQP package will be **DBAL-only** (no entities):
- No ORM needed for unit tests
- If AMQP functional tests added in future, Doctrine ORM must be configured correctly
- symfony/var-exporter 8.0+ incompatibility is critical to know

**Application to AMQP Package Testing:**
- Unit tests (routing, topology, connection factory) require NO Doctrine setup
- Keep `require-dev` clean - don't add unnecessary Doctrine dependencies
- If functional tests needed later, document ORM configuration requirements

**Version Constraints to Document:**
```json
{
    "require": {
        "php": ">=8.4"
    },
    "require-dev": {
        "symfony/var-exporter": "^6.4|^7.0|^8.0"
    }
}
```

**Note:** Using `symfony/var-exporter` 8.0 is fine because `enable_native_lazy_objects: true` uses PHP 8.4 native support.

---

### Learning 3: DI Configuration for Service Registration and Compiler Passes

**File:** `/Users/michal/code/freyr/message-broker/docs/solutions/test-failures/phase-1-test-implementation-discoveries.md`

**Applies to AMQP extraction:** YES - Service and test bootstrap configuration

**Key Issue Discovered:**
> "ServiceLocator syntax requires exact key names that will be used to fetch services from the locator. Keys must match what the code expects to retrieve."

**Critical Quote about ServiceLocator Configuration:**
```php
// ✅ CORRECT - ServiceLocator keys match what OutboxToAmqpBridge uses
Freyr\MessageBrokerAmqp\AmqpOutboxPublisher:
    arguments:
        $senderLocator: !service_locator
            amqp: '@messenger.transport.amqp'  # Key 'amqp' must match $senderName
```

**Code that Uses the ServiceLocator:**
```php
public function __construct(
    private ContainerInterface $senderLocator,  // ServiceLocator instance
    private AmqpRoutingStrategyInterface $routingStrategy,
) {}

// Later, in handle():
$senderName = $this->routingStrategy->getSenderName($event);  // Returns 'amqp'
$sender = $this->senderLocator->get($senderName);  // Gets @messenger.transport.amqp
```

**Why This Matters for AMQP Extraction:**

The plan shows `AmqpOutboxPublisher` using a `ServiceLocator`:
- Key names MUST match what the code expects to retrieve
- Current plan has hardcoded key `'amqp'` in the locator
- If `DefaultAmqpRoutingStrategy` returns different sender names, locator keys must match

**Application to `FreyrMessageBrokerAmqpExtension.php`:**

```php
// In services.yaml (or Extension class)
$definition->setArgument('$senderLocator', new Reference(
    'service_locator',
    ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE
));

// The service_locator must contain keys that match routing strategy output
```

**Validate in Tests:**
```php
public function testAmqpOutboxPublisherCanResolveAmqpSender(): void
{
    $publisher = $this->container->get(AmqpOutboxPublisher::class);

    // Verify sender can be resolved from locator
    // This fails fast if locator keys don't match routing strategy
}
```

---

### Learning 4: Test Bootstrap and Fresh Environment Setup

**File:** `/Users/michal/code/freyr/message-broker/docs/solutions/ci-issues/hidden-schema-failures-fresh-environment.md`

**Applies to AMQP extraction:** YES - Test infrastructure for new package

**Key Issue Discovered:**
> "Tests passing locally but failing in CI revealed environment parity issues. Long-lived local containers masked schema mismatches that only appeared in fresh CI environments."

**Critical Quote:**
```bash
# ❌ WRONG - Tests pass locally but fail in CI
docker compose up -d
docker compose run php vendor/bin/phpunit  # Passes (schema exists from prior runs)

# ✅ CORRECT - Test with fresh environment
docker compose down -v                      # Clean slate
docker compose up -d
docker compose run php vendor/bin/phpunit  # Must pass (no prior state)
```

**Why This Matters for AMQP Extraction:**

New package will be standalone:
- Must have independent test infrastructure
- Must verify tests pass in fresh environment (mimics CI)
- Cannot rely on core package state

**Application to AMQP Package Testing:**

**File: `tests/bootstrap.php`**
```php
<?php
// Bootstrap file for AMQP package unit tests
// No database setup needed for unit tests (no functional tests using DB)

// Only initialise what's needed:
// - Autoloader
// - Test environment variables
// - Mock/fixture setup if needed
```

**CI Workflow for AMQP Package:**
```yaml
# .github/workflows/tests.yml (in new freyr/message-broker-amqp repo)
# No database setup needed - unit tests only
# Run: composer install && vendor/bin/phpunit

# Fresh environment verification:
# docker compose not needed for unit tests
```

**Key Principle - Fresh Environment Checklist:**
```bash
# Before pushing:
cd /path/to/message-broker-amqp
composer install                    # Clean slate
vendor/bin/phpunit --testdox       # No external deps needed
```

---

### Learning 5: Schema and Database-Related Patterns

**File:** `/Users/michal/code/freyr/message-broker/docs/solutions/database-issues/migration-schema-mismatch-ci-vs-local.md`

**Applies to AMQP extraction:** NO - AMQP package has no database requirements

**Why Documented Here:**
- Provides context on core package schema decisions
- Helps understand why core uses BIGINT for messenger tables, BINARY(16) for deduplication
- Useful reference if AMQP package needs to document schema for end users

**Key Learning for Reference:**
```
Symfony Messenger transport tables MUST use BIGINT AUTO_INCREMENT
- messenger_outbox
- messenger_messages

Custom application tables CAN use BINARY(16) UUID v7
- message_broker_deduplication
```

**Not Applicable to AMQP Package:** No schema or database management in AMQP code.

---

## Key Takeaways for AMQP Extraction

### 1. Middleware Registration Pattern (CRITICAL)

**What NOT to Do:**
```php
// Service tag alone doesn't auto-register
class AmqpOutboxPublisher { }
// ... tagged with 'message_broker.outbox_publisher'
```

**What TO Do:**
- Core package must have `OutboxPublisherPass` compiler pass that:
  1. Finds services tagged `message_broker.outbox_publisher`
  2. Explicitly registers them in the outbox publisher registry
  3. Does NOT happen automatically from tag alone

**Implication for AMQP Package:**
- Tag service correctly: `tags: [{ name: 'message_broker.outbox_publisher', transport: 'outbox' }]`
- Core package MUST process the tag (verify this before extraction)
- Document dependency: "Core compiler pass required to discover this service"

---

### 2. Service Locator Keys Must Match Code Usage

**Pattern to Follow:**
```yaml
Freyr\MessageBrokerAmqp\AmqpOutboxPublisher:
    arguments:
        $senderLocator: !service_locator
            amqp: '@messenger.transport.amqp'  # Key 'amqp' hardcoded in routing strategy
```

**Verify:**
- `DefaultAmqpRoutingStrategy::getSenderName()` returns 'amqp' (or match configurable)
- Locator has key that matches routing strategy output
- Test: `testAmqpOutboxPublisherCanResolveSender()` fails if keys don't match

---

### 3. DI Configuration Documentation

**Create in AMQP Package:**
- Clear comments explaining what each service does
- Why certain arguments are needed (e.g., service locator, routing strategy)
- How configuration flows from `Configuration.php` → `Extension.php` → `services.yaml`

**Example from Plan (GOOD):**
```php
Freyr\MessageBrokerAmqp\AmqpOutboxPublisher:
    arguments:
        $senderLocator: !service_locator
            amqp: '@messenger.transport.amqp'  # Could be multiple senders
        $routingStrategy: '@...\AmqpRoutingStrategyInterface'
        $logger: '@logger'
    tags:
        - { name: 'message_broker.outbox_publisher', transport: 'outbox' }
```

---

### 4. Test Infrastructure for Standalone Package

**Unit Tests (AMQP has these):**
- No database needed
- No Doctrine setup needed
- Simple `phpunit.xml.dist` sufficient

**If Functional Tests Added Later:**
- Implement `setUpBeforeClass()` for schema setup
- Keep test schema separate from production migrations
- Test with fresh environment: `docker compose down -v`

---

### 5. Version Constraints and Compatibility

**AMQP Package Requirements:**
```json
{
    "require": {
        "php": ">=8.4",
        "freyr/message-broker": "^0.1",
        "ext-amqp": "*",
        "symfony/amqp-messenger": "^6.4|^7.0",
        "symfony/messenger": "^6.4|^7.0",
        "symfony/console": "^6.4|^7.0",
        "symfony/config": "^6.4|^7.0",
        "symfony/dependency-injection": "^6.4|^7.0",
        "symfony/http-kernel": "^6.4|^7.0"
    },
    "require-dev": {
        "php-amqplib/php-amqplib": "^3.7",
        "phpstan/phpstan": "^2.1",
        "symfony/var-exporter": "^6.4|^7.0|^8.0",
        "phpunit/phpunit": "^11.0|^12.0"
    }
}
```

**Important Notes:**
- `symfony/var-exporter` 8.0 is compatible (uses PHP 8.4 native lazy objects)
- No Doctrine ORM in production dependencies (DBAL-only)
- `php-amqplib` in `require-dev` only (not production)

---

## Risk Mitigation Checklist

### Before Extraction

- [ ] Verify core `OutboxPublisherPass` compiler pass exists and works
- [ ] Test that service tag `message_broker.outbox_publisher` is processed by core
- [ ] Confirm `AmqpOutboxPublisher` implements `OutboxPublisherInterface` from core
- [ ] Verify cross-package imports (core traits, interfaces) are available

### During Extraction

- [ ] Run full unit test suite for AMQP code before extraction
- [ ] No namespace conflicts with old `Freyr\MessageBroker\Amqp` in new package
- [ ] All cross-package imports reference `freyr/message-broker` correctly
- [ ] DI configuration tested: services can be resolved, tags registered

### After Extraction

- [ ] Core `OutboxPublisherPass` still finds AMQP publisher via tag
- [ ] Core tests still pass (no missing AMQP code)
- [ ] New package tests pass: `composer install && vendor/bin/phpunit`
- [ ] Fresh environment test: `cd package && composer install && vendor/bin/phpunit`
- [ ] CI workflows pass for both packages independently

---

## References to Solution Documents

1. **Critical Patterns:** `/Users/michal/code/freyr/message-broker/docs/solutions/patterns/critical-patterns.md`
   - Test environment schema setup (not applicable to AMQP unit tests, but documented for reference)

2. **Middleware Configuration:** `/Users/michal/code/freyr/message-broker/docs/solutions/test-failures/doctrine-transaction-middleware-orm-configuration.md`
   - Service tag registration patterns
   - DI configuration best practices
   - Why explicit configuration required (not auto-wired from tags)

3. **Test Infrastructure:** `/Users/michal/code/freyr/message-broker/docs/solutions/ci-issues/hidden-schema-failures-fresh-environment.md`
   - Environment parity (local vs CI)
   - Fresh environment testing checklist
   - Fail-fast principles for infrastructure

4. **Service Registration:** `/Users/michal/code/freyr/message-broker/docs/solutions/test-failures/phase-1-test-implementation-discoveries.md`
   - Service locator key management
   - Bootstrap configuration patterns
   - Testing service registration

5. **Database Schema (Reference):** `/Users/michal/code/freyr/message-broker/docs/solutions/database-issues/migration-schema-mismatch-ci-vs-local.md`
   - Context on core schema decisions (not applicable to AMQP, which has no database)

---

## Summary

**Extraction Readiness:** The plan at `docs/plans/2026-02-13-refactor-extract-amqp-package-plan.md` is **well-designed** and aligns with institutional patterns.

**Key Patterns to Follow:**
1. Service tag discovery must be processed by core compiler pass (not automatic)
2. Service locator keys must match routing strategy output
3. DI configuration should be explicit and well-documented
4. New package must test in fresh environment (CI simulation)
5. Cross-package dependencies must be correctly versioned

**No Blocker Issues Found:** All learnings support the plan rather than reveal problems.

**Critical Dependency:** Verify that core `OutboxPublisherPass.php` correctly processes `message_broker.outbox_publisher` tag before extraction begins.

---

**Generated:** 2026-02-13
**Search Scope:** `/Users/michal/code/freyr/message-broker/docs/solutions/`
**Files Evaluated:** 6 total, 4 relevant
