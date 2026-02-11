# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## ⚠️ REQUIRED WORKFLOW: Compound Engineering Plugin

**CRITICAL: All engineering work in this project MUST follow the Compound Engineering Plugin workflow.**

### Mandatory Workflow Process

For **ALL** new features, bug fixes, refactoring, or significant changes, you MUST follow this cycle:

1. **Brainstorm Phase** - `/workflows:brainstorm`
   - Explore requirements and approaches before planning
   - Understand constraints and trade-offs
   - Identify potential challenges

2. **Planning Phase** - `/workflows:plan`
   - Create detailed implementation plans
   - Break down work into concrete, actionable tasks
   - Document technical approach and architecture decisions
   - **80% of effort should go here** (not in coding)

3. **Execution Phase** - `/workflows:work`
   - Execute work items systematically using worktrees
   - Follow the plan created in step 2
   - Track progress through task completion
   - **Only 20% of effort should go here**

4. **Review Phase** - `/workflows:review`
   - Conduct comprehensive multi-agent code review
   - Use specialised reviewers for language/framework
   - Address all feedback before merging

5. **Documentation Phase** - `/workflows:compound`
   - Document learnings and patterns discovered
   - Add to knowledge base for future reusability
   - Make subsequent work easier

### Philosophy

> "Each unit of engineering work should make subsequent units easier—not harder."

- Emphasise planning over coding (80/20 rule)
- Compound knowledge with every completed task
- Use multi-agent review for quality assurance
- Document patterns for team learning

### Available Specialised Reviewers

When running `/workflows:review`, leverage these agents as appropriate:

**Language-Specific:**
- `kieran-rails-reviewer` - Rails code review
- `kieran-python-reviewer` - Python code review
- `kieran-typescript-reviewer` - TypeScript code review
- `dhh-rails-reviewer` - Rails from DHH's perspective

**Specialised Reviews:**
- `architecture-strategist` - Architectural decisions
- `performance-oracle` - Performance optimisation
- `security-sentinel` - Security audits
- `data-integrity-guardian` - Database migrations
- `pattern-recognition-specialist` - Patterns and anti-patterns
- `code-simplicity-reviewer` - Final simplicity pass

### Exception Handling

**When to skip the workflow:**
- Trivial documentation typo fixes
- Emergency hotfixes (document after the fact)
- Simple dependency updates

**For all other work:** Always start with `/workflows:brainstorm` or `/workflows:plan`.

---

## 📋 Critical Patterns - Required Reading

**IMPORTANT:** Before making changes to database setup, testing infrastructure, CI workflows, or core architecture, review:

**→ `docs/solutions/patterns/critical-patterns.md`**

This document contains patterns that MUST be followed to avoid recurring issues. Each pattern represents a mistake that was made multiple times or has significant impact.

**Current Critical Patterns:**
1. **Test Environment Schema Setup** - Schema setup must happen in test bootstrap, not as CI-specific steps

**Why this matters:**
- Prevents environment parity issues between local and CI
- Avoids silent failures that mask setup problems
- Ensures tests work in fresh environments
- Reduces debugging time for infrastructure issues

---

## Git/GitHub Conventions

### Branch Naming

Always name branches with the issue number for automatic linking:

```bash
# Preferred patterns:
git checkout -b 5-feature-name           # Simple and clear
git checkout -b feat/5-feature-name      # With type prefix
git checkout -b fix/5-bug-description    # For bug fixes

# Examples:
git checkout -b 10-add-retry-mechanism
git checkout -b feat/10-add-retry-mechanism
git checkout -b fix/15-race-condition-in-deduplication
git checkout -b hotfix/20-critical-security-patch
```

**Benefits:**
- GitHub automatically shows the branch in the issue sidebar
- Easy to find related code changes
- Clear intent and scope

### Commit Message Conventions

Follow Conventional Commits with issue references:

```bash
# Format:
<type>: <description>

<optional body>

<issue reference>

# Types:
feat:     New feature
fix:      Bug fix
refactor: Code refactoring (no functional change)
docs:     Documentation only
test:     Adding or updating tests
chore:    Maintenance (dependencies, config, etc.)
perf:     Performance improvement
style:    Code style/formatting (no functional change)

# Examples:
git commit -m "feat: add retry mechanism for failed messages

Implements exponential backoff with configurable max retries.
Uses Symfony Messenger retry strategy.

Part of #10"

git commit -m "fix: resolve race condition in deduplication check

- Add database-level unique constraint
- Handle UniqueConstraintViolationException
- Add test coverage for concurrent requests

Fixes #15"

git commit -m "docs: update database schema documentation

Part of #8"
```

**Issue Reference Keywords:**

Use these keywords to automatically link and close issues:

- **Link only:** `Part of #5`, `Related to #5`, `See #5`
- **Link and close when merged:** `Fixes #5`, `Closes #5`, `Resolves #5`

**Multi-commit Example:**

```bash
# First commit
git commit -m "feat: add deduplication store interface

Part of #10"

# Second commit
git commit -m "feat: implement DBAL deduplication store

Part of #10"

# Final commit
git commit -m "feat: integrate deduplication middleware

Completes retry mechanism implementation.

Fixes #10"
```

### Pull Request Conventions

Always use the closing keyword in PR descriptions:

```markdown
# PR Title (same as commit convention):
feat: Add retry mechanism for failed messages

# PR Description Template:
## Summary
Brief description of what this PR does (1-2 sentences).

Fixes #10
# or: Closes #10, Resolves #10

## Changes
- Bullet point list of key changes
- Use past tense (Added X, Fixed Y, Updated Z)

## Test Plan
How to verify this works:
1. Step one
2. Step two

## Related
- Related PRs: #8
- Documentation: docs/retry-mechanism.md
```

**Example PR Creation:**

```bash
# Create PR with proper description
gh pr create --title "feat: Add retry mechanism for failed messages" --body "$(cat <<'EOF'
## Summary
Implements exponential backoff retry mechanism for failed messages using
Symfony Messenger's built-in retry strategy.

Fixes #10

## Changes
- Added RetryStrategyInterface implementation
- Configured retry transport in messenger.yaml
- Added functional tests for retry behaviour
- Updated documentation

## Test Plan
1. Run functional tests: `vendor/bin/phpunit tests/Functional/RetryTest.php`
2. Manually trigger failed message: publish invalid event
3. Verify retry attempts in logs with exponential backoff

## Related
- Documentation: docs/retry-mechanism.md
EOF
)"
```

### Workflow Summary

**Complete workflow for new feature:**

```bash
# 1. Create GitHub issue first (or use existing issue number)
gh issue create --title "feat: Add retry mechanism" --body "Description..."
# Created issue #10

# 2. Create branch with issue number
git checkout -b 10-add-retry-mechanism

# 3. Make commits with issue references
git commit -m "feat: add retry strategy interface

Part of #10"

git commit -m "feat: implement exponential backoff

Part of #10"

# 4. Create PR with closing keyword
gh pr create --title "feat: Add retry mechanism" --body "
## Summary
Implements retry mechanism for failed messages.

Fixes #10

## Changes
- Added retry strategy
- Updated configuration
"

# 5. When PR merges to main, issue #10 automatically closes
```

### Benefits of This Convention

1. **Automatic Issue Tracking:** GitHub links commits/PRs to issues automatically
2. **Automatic Issue Closing:** Issues close when PR merges (using Fixes/Closes/Resolves)
3. **Clear History:** Easy to trace code changes back to requirements
4. **Better Collaboration:** Team members can see what's being worked on
5. **Release Notes:** Conventional commits enable automated changelog generation

### Tools Integration

This convention works with:
- **GitHub Actions:** Auto-close issues on merge
- **Release Please:** Automated version bumping and changelogs
- **Semantic Release:** Automated releases based on commit messages
- **Git History:** `git log --oneline --grep="feat:"` to find features

---

## Overview

This is the **Freyr Message Broker** package - a standalone Symfony bundle providing production-ready implementations of the **Inbox** and **Outbox** patterns for reliable event publishing and consumption with transactional guarantees and automatic deduplication.

**Key Technology Stack:**
- PHP 8.4+
- Symfony Messenger 7.3+
- Doctrine DBAL/ORM 3+
- MySQL/MariaDB with binary UUID v7 support
- RabbitMQ/AMQP (php-amqplib)
- freyr/identity package for UUID v7

## Architecture

### Outbox Pattern (Publishing Events)
Events are stored in a database table within the same transaction as business data, then asynchronously published using a **middleware-based architecture**:

```
Domain Event → Event Bus (Messenger)
→ MessageIdStampMiddleware (adds MessageIdStamp at dispatch time)
→ Outbox Transport (doctrine://) → messenger_outbox table (transactional)
→ messenger:consume outbox → OutboxToAmqpBridge middleware (reads existing stamp)
→ AMQP (with routing strategy, via direct SenderInterface)
```

**Key Features:**
- **Stable Message ID:** `MessageIdStampMiddleware` generates UUID v7 at dispatch time, ensuring deduplication survives redelivery
- **Middleware Bridge:** `OutboxToAmqpBridge` reads existing `MessageIdStamp` from envelope (never generates new ones)
- **Direct Sender:** Bridge publishes via `SenderInterface` (no nested bus dispatch, no nested savepoints)
- **AMQP Routing Strategy:** Determines exchange, routing key, and headers
- **Convention-Based Routing:** Automatic routing with attribute overrides

### Inbox Pattern (Consuming Events)
Events are consumed from AMQP natively with deduplication using middleware-based approach:

```
AMQP Transport (native Symfony Messenger)
→ InboxSerializer translates 'type' header (semantic name → FQN)
→ Native Symfony Serializer deserializes body + stamps from X-Message-Stamp-* headers
→ Routes to handler (based on PHP class)
→ DeduplicationMiddleware (checks message_broker_deduplication table)
→ If duplicate: skip handler | If new: INSERT + process
→ Application Handler → Business Logic (all within transaction)
```

### Key Innovation: "Fake FQN" Pattern + Native Stamp Handling
- **Native Transport**: Uses Symfony Messenger's built-in AMQP transport (no custom commands)
- **Split Serializers**: Separate serializers for inbox and outbox flows to handle different requirements:
  - **OutboxSerializer**: Extracts semantic name from `#[MessageName]` attribute during encoding (publishing)
  - **InboxSerializer**: Translates semantic name to FQN during decoding (consuming), uses default encoding for failed message retries
- **Why Split?**: Inbox messages don't have `#[MessageName]` attribute, so they need default encoding when being retried/stored in failed transport
- **Native Stamp Handling**: Stamps (MessageIdStamp, MessageNameStamp) automatically serialized/deserialized via `X-Message-Stamp-*` headers by Symfony
- **DeduplicationMiddleware**: Runs AFTER `doctrine_transaction` middleware (priority -10), ensuring deduplication checks happen within the transaction
- **Atomic Guarantees**: If handler succeeds, both deduplication entry and business logic changes are committed atomically
- **Retry Safety**: If handler fails, transaction rolls back, allowing message to be retried

## Directory Structure

```
messenger/
├── src/
│   ├── Command/                    # Console Commands
│   │   └── DeduplicationStoreCleanup.php  # Cleanup old deduplication records
│   ├── Doctrine/                   # Doctrine Integration
│   │   └── Type/
│   │       └── IdType.php          # Binary UUID v7 Doctrine type
│   ├── Inbox/                      # Inbox Pattern Implementation
│   │   ├── DeduplicationDbalStore.php     # DBAL-based deduplication store
│   │   ├── DeduplicationMiddleware.php    # Middleware for inbox deduplication
│   │   ├── DeduplicationStore.php         # Deduplication store interface
│   │   └── MessageIdStamp.php             # Message ID stamp for deduplication
│   ├── Outbox/                     # Outbox Pattern Implementation
│   │   ├── EventBridge/
│   │   │   ├── OutboxMessage.php          # Marker interface for outbox events
│   │   │   └── OutboxToAmqpBridge.php     # Bridge middleware (reads existing MessageIdStamp)
│   │   ├── MessageIdStampMiddleware.php   # Stamps OutboxMessage with MessageIdStamp at dispatch
│   │   ├── Routing/
│   │   │   ├── AmqpRoutingKey.php         # Attribute for custom routing key
│   │   │   ├── AmqpRoutingStrategyInterface.php
│   │   │   ├── DefaultAmqpRoutingStrategy.php
│   │   │   └── MessengerTransport.php     # Attribute for custom exchange
│   │   └── MessageName.php                # Attribute for semantic message names
│   ├── Serializer/                 # Serialization Infrastructure
│   │   ├── Normalizer/
│   │   │   ├── CarbonImmutableNormalizer.php
│   │   │   └── IdNormalizer.php
│   │   ├── InboxSerializer.php     # Inbox serializer (semantic name → FQN)
│   │   ├── MessageNameStamp.php    # Stamp for preserving semantic names
│   │   └── OutboxSerializer.php    # Outbox serializer (FQN → semantic name)
│   └── FreyrMessageBrokerBundle.php
├── docs/                           # Comprehensive architecture documentation
└── README.md                       # Full user guide
```

## Common Commands

### Running Outbox Worker (Publishing)
```bash
php bin/console messenger:consume outbox -vv
```

### Running Inbox Consumer (AMQP to Handlers)
**Prerequisites**: Queue must already exist in RabbitMQ with proper bindings configured.

Consume directly from AMQP transport (one worker per queue):
```bash
# Example: consume from amqp_orders transport
php bin/console messenger:consume amqp_orders -vv
```

Messages are automatically:
1. Deserialized by InboxSerializer into typed PHP objects
2. Deduplicated by DeduplicationMiddleware
3. Routed to handlers based on PHP class

### Testing Deduplication
```bash
# Send 3 identical messages
php bin/console fsm:test-inbox-dedup

# Check database - should have only 1 row
php bin/console dbal:run-sql "SELECT HEX(id), queue_name FROM messenger_messages WHERE queue_name='inbox'"
```

### Monitoring & Maintenance
```bash
# View queue statistics
php bin/console messenger:stats

# View failed messages
php bin/console messenger:failed:show

# Retry failed messages
php bin/console messenger:failed:retry

# Clean up old outbox messages (older than 7 days) - OPTIONAL
php bin/console messenger:cleanup-outbox --days=7 --batch-size=1000

# Note: This is optional housekeeping. Symfony marks messages as delivered but doesn't
# auto-delete them. Run periodically (cron/scheduler) to prevent messenger_outbox growth.
```

## Configuration Requirements

### Database Schema - 3-Table Architecture

**IMPORTANT:** The package uses a **3-table approach** for optimal performance:

1. **`messenger_outbox`** - Dedicated outbox table for publishing events
2. **`message_broker_deduplication`** - Deduplication tracking (binary UUID v7 PK)
3. **`messenger_messages`** - Standard table for failed messages (shared monitoring)

See **`docs/database-schema.md`** for complete schemas, migration examples, cleanup strategies, and performance considerations.

### Messenger Configuration (messenger.yaml)
The package requires specific messenger transport configuration:

```yaml
framework:
  messenger:
    # Failure transport for handling failed messages
    failure_transport: failed

    default_middleware:
      enabled: true
      allow_no_handlers: false

    buses:
      messenger.bus.default:
        middleware:
          - 'Freyr\MessageBroker\Outbox\MessageIdStampMiddleware'  # Stamps OutboxMessage at dispatch
          - doctrine_transaction                                     # Starts transaction
          - 'Freyr\MessageBroker\Outbox\EventBridge\OutboxToAmqpBridge'  # Publishes outbox → AMQP
          - 'Freyr\MessageBroker\Inbox\DeduplicationMiddleware'          # Inbox deduplication

    transports:
      # Outbox transport - AUTO-MANAGED (auto_setup: true)
      # Stores domain events with #[MessageName]
      # Consumed by OutboxToAmqpBridge, uses default serializer
      outbox:
        dsn: 'doctrine://default?table_name=messenger_outbox&queue_name=outbox'
        options:
          auto_setup: true  # Symfony creates table automatically
        # No custom serializer needed - messages have #[MessageName] attribute
        retry_strategy:
          max_retries: 3
          delay: 1000
          multiplier: 2

      # AMQP publish transport - MANUAL MANAGEMENT (auto_setup: false)
      # OutboxToAmqpBridge publishes here
      # Uses OutboxSerializer to translate FQN → semantic name
      amqp:
        dsn: '%env(MESSENGER_AMQP_DSN)%'
        serializer: 'Freyr\MessageBroker\Serializer\OutboxSerializer'
        options:
          auto_setup: false  # Infrastructure managed by ops
        retry_strategy:
          max_retries: 3
          delay: 1000
          multiplier: 2

      # AMQP consumption transport - MANUAL MANAGEMENT (auto_setup: false)
      # Uses InboxSerializer
      amqp_orders:
        dsn: '%env(MESSENGER_AMQP_DSN)%'
        serializer: 'Freyr\MessageBroker\Serializer\InboxSerializer'
        options:
          auto_setup: false  # Infrastructure managed by ops
          queue:
            name: 'orders_queue'
        retry_strategy:
          max_retries: 3
          delay: 1000
          multiplier: 2

      # Failed transport - AUTO-MANAGED (auto_setup: true)
      failed:
        dsn: 'doctrine://default?queue_name=failed'
        options:
          auto_setup: true  # Symfony creates table automatically

    routing:
    # Outbox messages - route domain events to outbox transport
    # Example:
    # 'App\Domain\Event\OrderPlaced': outbox
    # 'App\Domain\Event\UserRegistered': outbox

    # Inbox messages (consumed from AMQP transports)
    # Messages are deserialized by InboxSerializer into typed objects
    # DeduplicationMiddleware automatically prevents duplicate processing
    # Handlers execute synchronously (no routing needed - AMQP transport handles delivery)
    # Example handlers:
    # #[AsMessageHandler]
    # class OrderPlacedHandler { public function __invoke(OrderPlaced $message) {} }
```

**Auto-Setup Policy:**
- **Doctrine transports (outbox, failed)**: `auto_setup: true` - Symfony manages tables automatically
- **AMQP transports**: `auto_setup: false` - Infrastructure managed separately (RabbitMQ exchanges/queues)
- **Deduplication table**: Manual migration required (custom binary UUID v7 schema)

### Doctrine Configuration
Register the custom UUID type in `config/packages/doctrine.yaml`:
```yaml
doctrine:
    dbal:
        types:
            id_binary: Freyr\MessageBroker\Doctrine\Type\IdType
```

### Services Configuration
```yaml
message_broker:
  inbox:
    # Message type mapping: message_name => PHP class
    # Used by InboxSerializer to translate semantic names to FQN during deserialization
    message_types:
    # Examples:
    # 'order.placed': 'App\Message\OrderPlaced'
    # 'user.registered': 'App\Message\UserRegistered'
```

```yml
services:
  _defaults:
    autowire: false
    autoconfigure: false
    public: false

  # Doctrine Integration
  Freyr\MessageBroker\Doctrine\Type\IdType:
    tags:
      - { name: 'doctrine.dbal.types', type: 'id_binary' }

  # Auto-register all Normalizers using Symfony's native tag
  # These will be automatically added to the @serializer service
  Freyr\MessageBroker\Serializer\Normalizer\:
    resource: '../src/Serializer/Normalizer/'
    tags: ['serializer.normalizer']

  # Custom ObjectNormalizer with property promotion support
  # This overrides Symfony's default ObjectNormalizer with propertyTypeExtractor
  # Lower priority (-1000) ensures it runs as fallback after specialized normalizers
  Freyr\MessageBroker\Serializer\Normalizer\PropertyPromotionObjectNormalizer:
    autowire: true
    class: Symfony\Component\Serializer\Normalizer\ObjectNormalizer
    arguments:
      $propertyTypeExtractor: '@property_info'
    tags:
      - { name: 'serializer.normalizer', priority: -1000 }

  # Inbox Serializer - for AMQP consumption
  # - decode(): Translates semantic name to FQN (e.g., 'order.placed' → 'App\Message\OrderPlaced')
  # - encode(): Preserves semantic name via MessageNameStamp (for retry/failed scenarios)
  # Injects native @serializer service with all registered normalizers
  Freyr\MessageBroker\Serializer\InboxSerializer:
    arguments:
      $serializer: '@serializer'
      $messageTypes: '%message_broker.inbox.message_types%'

  # Outbox Serializer - for AMQP publishing
  # - encode(): Extracts semantic name from #[MessageName] (e.g., 'App\Event\OrderPlaced' → 'order.placed')
  # - decode(): Restores FQN from X-Message-Class header (for retry/failed scenarios)
  # Injects native @serializer service with all registered normalizers
  Freyr\MessageBroker\Serializer\OutboxSerializer:
    arguments:
      $serializer: '@serializer'

  # Deduplication Store (DBAL implementation)
  Freyr\MessageBroker\Inbox\DeduplicationStore:
    class: Freyr\MessageBroker\Inbox\DeduplicationDbalStore
    arguments:
      $connection: '@doctrine.dbal.default_connection'
      $logger: '@logger'

  # Deduplication Middleware (inbox pattern)
  # Runs AFTER doctrine_transaction middleware (priority -10)
  Freyr\MessageBroker\Inbox\DeduplicationMiddleware:
    arguments:
      $store: '@Freyr\MessageBroker\Inbox\DeduplicationStore'
    tags:
      - { name: 'messenger.middleware', priority: -10 }

  # AMQP Routing Strategy (default convention-based routing)
  Freyr\MessageBroker\Outbox\Routing\AmqpRoutingStrategyInterface:
    class: Freyr\MessageBroker\Outbox\Routing\DefaultAmqpRoutingStrategy

  # MessageIdStamp Middleware
  # Stamps OutboxMessage envelopes with MessageIdStamp at dispatch time
  Freyr\MessageBroker\Outbox\MessageIdStampMiddleware:
    tags:
      - { name: 'messenger.middleware' }

  # Outbox Bridge (middleware — publishes outbox events to AMQP)
  # Reads existing MessageIdStamp from envelope and publishes via direct SenderInterface
  Freyr\MessageBroker\Outbox\EventBridge\OutboxToAmqpBridge:
    arguments:
      $amqpSender: '@messenger.transport.amqp'
      $routingStrategy: '@Freyr\MessageBroker\Outbox\Routing\AmqpRoutingStrategyInterface'
      $logger: '@logger'
    tags:
      - { name: 'messenger.middleware' }

  # Deduplication Store Cleanup Command (optional maintenance)
  # Removes old idempotency records from the deduplication store
  Freyr\MessageBroker\Command\DeduplicationStoreCleanup:
    arguments:
      $connection: '@doctrine.dbal.default_connection'
    tags: ['console.command']
```

## Development Guidelines

### Domain Events Must Use #[MessageName] Attribute ✨

```php
use Freyr\MessageBroker\Outbox\MessageName;
use Freyr\MessageBroker\Outbox\EventBridge\OutboxMessage;
use Freyr\MessageBroker\Outbox\Routing\{MessengerTransport, AmqpRoutingKey};
use Freyr\Identity\Id;

#[MessageName('order.placed')]  // REQUIRED: Message name for routing
#[MessengerTransport('commerce')]     // OPTIONAL: Override default exchange
#[AmqpRoutingKey('order.test')] // OPTIONAL: Override default routing key
final readonly class OrderPlaced implements OutboxMessage
{
    public function __construct(
        public Id $orderId,
        public float $totalAmount,
        public CarbonImmutable $placedAt,
    ) {}
}
```

**Critical Requirements:**
1. Every outbox event MUST have `#[MessageName('domain.subdomain.action')]` attribute
2. Every outbox event MUST implement `OutboxMessage` marker interface
3. NO `messageId` property - it's auto-generated by `MessageIdStampMiddleware` at dispatch time as UUID v7

**AMQP Routing (Optional):**
4. Use `#[MessengerTransport('name')]` to override default exchange (first 2 parts of message name)
5. Use `#[AmqpRoutingKey('key')]` to override default routing key (full message name)

See `docs/amqp-routing.md` for complete routing documentation.

### Database Schema Requirements ✨ **3-TABLE ARCHITECTURE**

**Table Management Strategy:**

**Auto-Managed by Symfony (no manual setup needed):**
1. **`messenger_outbox`** - Outbox-specific standard Doctrine transport (auto_setup: true)
2. **`messenger_messages`** - Standard for failed messages (auto_setup: true)

**Application-Managed (manual migration required):**
3. **`message_broker_deduplication`** - Deduplication tracking (binary(16) message_id PK)

**Key Points:**
- Outbox and failed tables are **auto-created** by Symfony on first worker run (auto_setup: true)
- Only deduplication table requires manual migration (custom binary UUID v7 schema)
- Outbox table isolated for publishing performance
- Failed messages → `messenger_messages` table (unified monitoring)
- Required Doctrine custom type: `id_binary` (provided by `Freyr\MessageBroker\Doctrine\Type\IdType`)
- Register the type in Doctrine configuration
- Deduplication is handled by **DeduplicationMiddleware** using `message_broker_deduplication` table
- Middleware runs AFTER `doctrine_transaction` → atomic commit of deduplication entry + handler changes
- **Recommended flow**: AMQP → InboxSerializer → DeduplicationMiddleware → Handler (no inbox transport needed)

**See:** `docs/database-schema.md` for complete migration examples and rationale.

### Inbox Message Handling (Typed Objects)

The inbox uses `InboxSerializer` to translate semantic message names to PHP classes, then Symfony's native serializer deserializes into typed PHP objects:

**1. Define Message Class**
```php
namespace App\Message;

use Freyr\Identity\Id;
use Carbon\CarbonImmutable;

final readonly class OrderPlaced
{
    public function __construct(
        public Id $orderId,
        public Id $customerId,
        public float $totalAmount,
        public CarbonImmutable $placedAt,
    ) {}
}
```

**2. Configure Message Type Mapping**
```yaml
# config/packages/message_broker.yaml
message_broker:
    inbox:
        message_types:
            'order.placed': 'App\Message\OrderPlaced'
            'user.registered': 'App\Message\UserRegistered'
```

**3. Use Standard Messenger Handlers**
```php
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class OrderPlacedHandler
{
    public function __invoke(OrderPlaced $message): void
    {
        // Type-safe access with IDE autocomplete!
        $orderId = $message->orderId;
        $amount = $message->totalAmount;
        // Process...
    }
}
```

**Benefits:**
- ✅ Type safety and IDE support
- ✅ Native Symfony serialization (uses @serializer service)
- ✅ Supports value objects (Id, CarbonImmutable, enums) via custom normalizers
- ✅ Semantic message names (language-agnostic)
- ✅ Stamps automatically handled via X-Message-Stamp-* headers
- ✅ Minimal custom code (~50 lines per serializer)
- ✅ Failed message retry safety (InboxSerializer uses default encoding without #[MessageName] requirement)

See `docs/inbox-typed-messages.md` for complete guide.

### Custom Serialization with Normalizers/Denormalizers ✨

The package uses **Symfony Serializer** with custom normalizers/denormalizers for type handling. This allows applications to add their own serialization logic for custom types.

**Package-Provided Normalizers:**
- `IdNormalizer` - For `Freyr\Identity\Id` (UUID v7) - implements both NormalizerInterface and DenormalizerInterface
- `CarbonImmutableNormalizer` - For `Carbon\CarbonImmutable` - implements both NormalizerInterface and DenormalizerInterface

**Adding Custom Normalizers:**

1. **Create a Normalizer (implements both interfaces):**
```php
namespace App\Serializer\Normalizer;

use App\ValueObject\Money;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final readonly class MoneyNormalizer implements NormalizerInterface, DenormalizerInterface
{
    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        return [
            'amount' => $object->getAmount(),
            'currency' => $object->getCurrency(),
        ];
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Money;
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): Money
    {
        return new Money($data['amount'], $data['currency']);
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return $type === Money::class;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Money::class => true];
    }
}
```

2. **Auto-register all normalizers from a folder:**
```yaml
services:
    App\Serializer\Normalizer\:
        resource: '../src/Serializer/Normalizer/'
        tags: ['serializer.normalizer']
```

3. **Control normalizer order with priority (optional):**
```yaml
services:
    # Higher priority = earlier in the normalizer chain
    App\Serializer\Normalizer\MoneyNormalizer:
        tags:
            - { name: 'serializer.normalizer', priority: 10 }

    # Lower priority = later in the normalizer chain
    App\Serializer\Normalizer\GenericValueObjectNormalizer:
        tags:
            - { name: 'serializer.normalizer', priority: -50 }
```

**How It Works:**
- Both serializers (`InboxSerializer` and `OutboxSerializer`) inject Symfony's native `@serializer` service
- Symfony automatically collects all normalizers tagged with `serializer.normalizer`
- Normalizers are ordered by priority (higher priority = earlier in chain, default = 0)
- Custom `PropertyPromotionObjectNormalizer` (configured with `propertyTypeExtractor`) replaces Symfony's default `ObjectNormalizer`
- This custom ObjectNormalizer has priority -1000, ensuring it runs last as a fallback
- The serializer uses the appropriate normalizer based on type detection

**Property Promotion Support:**
The custom `ObjectNormalizer` is configured with `propertyTypeExtractor` to support PHP 8 constructor property promotion:
```php
final readonly class OrderPlaced
{
    public function __construct(
        public Id $orderId,           // ✅ Property promotion works!
        public float $totalAmount,
        public CarbonImmutable $placedAt,
    ) {}
}
```

**Benefits:**
- ✅ Uses Symfony's native serializer service - standard and well-tested
- ✅ Applications can add serialization for their own types by tagging normalizers
- ✅ No manual enumeration needed - normalizers are auto-discovered via tagged iterator
- ✅ Property promotion support via `propertyTypeExtractor` configuration
- ✅ Priority system allows fine-grained control over normalization order
- ✅ Extensible architecture - add normalizers by simply tagging them with `serializer.normalizer`
- ✅ Follows Symfony best practices
- ✅ Supports complex nested objects
- ✅ Single source of truth - one @serializer service for entire application
- ✅ Normalizers work with any Symfony component that uses the serializer service

### Outbox Bridge Pattern ✨ **MIDDLEWARE + DIRECT SENDER**

The outbox pattern uses two middleware working together:

1. **`MessageIdStampMiddleware`** — stamps `OutboxMessage` envelopes at dispatch time (before outbox storage)
2. **`OutboxToAmqpBridge`** — intercepts outbox-consumed messages and publishes to AMQP via direct `SenderInterface`

```php
// ✅ Middleware stamps OutboxMessage at dispatch time
final readonly class MessageIdStampMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$envelope->getMessage() instanceof OutboxMessage) {
            return $stack->next()->handle($envelope, $stack);
        }
        if ($envelope->last(ReceivedStamp::class) !== null) {
            return $stack->next()->handle($envelope, $stack);
        }
        if ($envelope->last(MessageIdStamp::class) === null) {
            $envelope = $envelope->with(new MessageIdStamp((string) Id::new()));
        }
        return $stack->next()->handle($envelope, $stack);
    }
}
```

```php
// ✅ Bridge middleware reads existing stamp and publishes to AMQP
final readonly class OutboxToAmqpBridge implements MiddlewareInterface
{
    public function __construct(
        private SenderInterface $amqpSender,
        private AmqpRoutingStrategyInterface $routingStrategy,
        private LoggerInterface $logger,
        private string $outboxTransportName = 'outbox',
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        // Only process OutboxMessage consumed from outbox transport
        if (!$envelope->getMessage() instanceof OutboxMessage) {
            return $stack->next()->handle($envelope, $stack);
        }
        $receivedStamp = $envelope->last(ReceivedStamp::class);
        if (!$receivedStamp instanceof ReceivedStamp
            || $receivedStamp->getTransportName() !== $this->outboxTransportName) {
            return $stack->next()->handle($envelope, $stack);
        }

        // Read existing MessageIdStamp (added at dispatch time)
        $messageIdStamp = $envelope->last(MessageIdStamp::class)
            ?? throw new RuntimeException('...');

        // Publish directly to AMQP (no nested bus dispatch)
        $this->amqpSender->send(new Envelope($event, [
            $messageIdStamp,
            new AmqpStamp($routingKey, AMQP_NOPARAM, $headers),
        ]));

        // Short-circuit: HandleMessageMiddleware has no handler for OutboxMessage
        return $envelope;
    }
}
```

**Benefits:**
- Stable message IDs survive redelivery (deduplication guaranteed)
- No nested bus dispatch (no nested savepoints, no double middleware traversal)
- No code changes needed when adding new events
- Convention-based routing with attribute overrides
- Failed publishing handled by Messenger's retry/failed transport

### Scaling Considerations
- Run multiple AMQP consumers: one per queue (e.g., `messenger:consume amqp_orders`) - recommended approach
- Run multiple outbox workers: `messenger:consume outbox`
- All workers support horizontal scaling with SKIP LOCKED

## Important Implementation Details

1. **3-Table Architecture**: The package uses dedicated tables for outbox/deduplication/failed:
   - `messenger_outbox` (table_name in doctrine:// DSN for publishing)
   - `message_broker_deduplication` (deduplication tracking for consumed messages)
   - `messenger_messages` (standard for failed messages)

2. **"Fake FQN" Pattern with Split Serializers**: The package uses separate serializers for different flows:
   - **Publishing (OutboxSerializer)**: Extracts semantic name from `#[MessageName]` attribute and sets `type` header (e.g., `order.placed`)
   - **Consuming (InboxSerializer)**: Translates semantic name → FQN during decode, uses default encode for failed retries
   - **Why Split?**: Inbox consumer messages don't have `#[MessageName]` attribute, so they need vanilla encoding when being retried/stored in failed transport
   - **Stamps**: Automatically serialized/deserialized via `X-Message-Stamp-*` headers by Symfony
   - **Result**: External systems see semantic names, internal code uses native Symfony patterns, failed messages can be retried safely

3. **DeduplicationMiddleware**: The middleware runs AFTER `doctrine_transaction` middleware (priority -10):
   - Checks `MessageIdStamp` on incoming messages (restored automatically from headers)
   - Uses PHP class FQN from `$envelope->getMessage()::class` as message name
   - Attempts INSERT into `message_broker_deduplication` table
   - If duplicate (UniqueConstraintViolationException): skips handler execution
   - If new: processes message normally
   - Transaction commits: deduplication entry + handler changes are atomic
   - Transaction rolls back: deduplication entry is rolled back, message can be retried

4. **AMQP Infrastructure Requirements**: Native AMQP transport consumption assumes RabbitMQ infrastructure is already configured:
   - Queues must exist
   - Exchanges must exist
   - Queue-to-exchange bindings must be configured

   Symfony Messenger AMQP transport only consumes from existing queues; it does not declare or bind queues/exchanges (unless auto_setup is enabled).

5. **AMQP Consumer ACK Behavior**: Native AMQP transport ACKs messages after successful handler execution. Messages are NACK'd if they fail validation (InboxSerializer) or if handlers throw exceptions.

6. **Binary UUID v7 Storage**: The `message_broker_deduplication` table uses binary(16) for message_id column (primary key). Outbox table (`messenger_outbox`) uses binary(16) for id column. This is a hard requirement enforced by global CLAUDE.md settings.

8. **Message Format**: AMQP messages use native Symfony serialization with semantic `type` header:
   ```
   Headers:
     type: order.placed  (semantic message name)
     X-Message-Stamp-MessageIdStamp: [{"messageId":"01234567-89ab..."}]  (generated at dispatch time by MessageIdStampMiddleware)

   Body (only business data):
   {
     "orderId": "550e8400-e29b-41d4-a716-446655440000",
     "totalAmount": 123.45,
     "placedAt": "2025-10-08T13:30:00+00:00"
   }
   ```
   - **type header**: Semantic message name (e.g., `order.placed`) - language-agnostic
   - **X-Message-Stamp-*** headers: Symfony stamps (MessageIdStamp, etc.) - generated at dispatch time by MessageIdStampMiddleware
   - **Body**: Native Symfony serialization of the message object (business data only, no messageId)
   - **messageId**: NOT in payload - it's transport metadata in MessageIdStamp header

   The InboxSerializer translates the `type` header from semantic name to PHP FQN during consumption.

9. **Transactional Guarantees**:
   - **Outbox**: Events are only published if the business transaction commits successfully (atomicity)
   - **Inbox**: Deduplication entry and handler changes are committed in the same transaction (atomicity)

10. **At-Least-Once Delivery**: System guarantees events are delivered at least once; consumers must be idempotent (enforced by DeduplicationMiddleware).

## Namespace Convention

All classes in this package use the `Freyr\MessageBroker` namespace:
- `Freyr\MessageBroker\Inbox\*`
- `Freyr\MessageBroker\Outbox\*`

## Documentation

Comprehensive documentation is available in the `docs/` directory:
The main `README.md` provides a complete user guide with examples.

## Monitoring in Production

Deploy workers using systemd, supervisor, or Docker with:
- Time limits (e.g., `--time-limit=3600`)
- Automatic restart on failure
- Multiple replicas for high availability

Track metrics:
- Outbox queue depth (`messenger:stats`)
- Inbox processing lag
- Failed message count
- Worker health/uptime
