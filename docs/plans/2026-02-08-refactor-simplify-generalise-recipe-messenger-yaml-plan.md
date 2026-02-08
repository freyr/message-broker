---
title: "refactor: Simplify and generalise recipe messenger.yaml configuration"
type: refactor
date: 2026-02-08
issue: 11
---

# refactor: Simplify and generalise recipe messenger.yaml configuration

## Overview

The Symfony Flex recipe's `messenger.yaml` is too specific and opinionated for a library recipe. It contains domain-specific transport names (`amqp_orders`, `orders_queue`), hardcoded retry strategies, a `default_middleware` nesting bug, and incorrect AMQP queue syntax. This plan replaces all domain-specific values with `your_`-prefixed placeholder names, fixes configuration bugs, adds integrator guidance, and updates supporting files.

Fixes #11

## Problem Statement

The recipe is the first configuration a new user sees after `composer require`. Currently it:

1. **Misleads** with domain-specific names (`amqp_orders`, `orders_queue`) that look required
2. **Contains a bug** — `default_middleware` is a sibling of `buses:` instead of nested under `buses.messenger.bus.default` (confirmed via `config/reference.php:458-462`)
3. **Uses incorrect AMQP syntax** — `queue: name:` (singular) instead of `queues:` (plural map), inconsistent with the test config and Symfony AMQP transport docs
4. **Lacks guidance** — no TODO markers, no header comment, no exchange/queue examples
5. **Has stale post-install output** — suggests `messenger:consume outbox amqp -vv` (combined workers)

## Proposed Solution

### Naming Convention

Use `your_` prefix for all placeholder values. This follows Symfony's own skeleton pattern (e.g. `APP_SECRET=your_app_secret`) and is impossible to mistake for a real value.

| Slot | Current | Proposed |
|------|---------|----------|
| AMQP consume transport | `amqp_orders` | `amqp_your_inbox` |
| Queue name | `orders_queue` | `your_app_inbox` |
| Exchange name | *(missing)* | `your_app_events` (commented) |
| Routing class examples | `App\Domain\Event\OrderPlaced` | `App\YourDomain\Event\YourEvent` |

**Fixed names (not renamed — referenced in production PHP):**
- `outbox` — hardcoded in `OutboxToAmqpBridge` attribute `#[AsMessageHandler(fromTransport: 'outbox')]`
- `amqp` — default fallback in `DefaultAmqpRoutingStrategy::getTransport()` returns `'amqp'`
- `failed` — standard Symfony convention for failure transport

### Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Retry strategies | Remove from all transports, provide as commented example on one | Symfony defaults are sensible (3 retries, 1s delay, x2 multiplier). Avoids repeated boilerplate. |
| Exchange/queue config | Commented examples under AMQP transports | Users must configure infrastructure; showing the shape prevents guesswork |
| Multiple inbox pattern | Commented second transport block | Common use case; single comment line saying "duplicate this block" is insufficient |
| Header comment | Concise 4-transport overview with docs reference | Users need architecture context without a wall of text |
| TODO marker format | `# TODO:` (uppercase, YAML comment) | IDE-recognised, grep-friendly, consistent with existing recipe patterns |
| Outbox serialiser | Omit (use Symfony default) | Per learning from `docs/plans/2026-02-06-fix-recipe-serialiser-class-references-plan.md` — outbox Doctrine transport stores native PHP objects |

## Technical Approach

### Scope: 3 files, 0 production PHP changes, 0 test changes

| File | Change Type | Risk |
|------|------------|------|
| `recipe/1.0/config/packages/messenger.yaml` | Rewrite | None — recipe config is independent from test/production code |
| `recipe/1.0/manifest.json` | Edit post-install output | None — cosmetic only |
| `recipe/1.0/README.md` | Update transport name references | None — documentation only |

### File 1: `recipe/1.0/config/packages/messenger.yaml`

**Changes from current file:**

1. **Header comment** — 9-line block explaining the 4 transports and placeholder convention
2. **`default_middleware` nesting** — moved from sibling of `buses:` to under `buses.messenger.bus.default` (bug fix)
3. **Transport rename** — `amqp_orders` → `amqp_your_inbox`
4. **Queue syntax** — removed `queue: name: 'orders_queue'`, replaced with commented `queues:` map (correct Symfony AMQP syntax)
5. **Exchange config** — added commented exchange blocks on both `amqp` and `amqp_your_inbox`
6. **Retry strategies** — removed from all 4 transports, single commented example on outbox
7. **Multiple inbox example** — added commented second inbox transport block
8. **TODO markers** — 3 markers at customisation points (exchange, inbox transport, routing)
9. **Routing section** — simplified to single commented example with `your_` placeholder
10. **Removed verbose inline comments** about inbox handlers (belongs in docs, not config)

### File 2: `recipe/1.0/manifest.json`

- Split combined worker command into separate commands
- Added placeholder reminder step
- Uses `amqp_your_inbox` (matching the new transport name)

### File 3: `recipe/1.0/README.md`

Find-and-replace: `amqp_orders` → `amqp_your_inbox`, `orders_queue` → `your_app_inbox`

## Acceptance Criteria

- [x] `amqp_orders` renamed to generic name → `amqp_your_inbox`
- [x] Exchange/queue configuration provided as commented examples → on both AMQP transports
- [x] `default_middleware` nesting corrected → moved under `buses.messenger.bus.default`
- [x] Retry strategies simplified → removed, single commented example provided
- [x] Clear `# TODO:` markers for integrator customisation points → 3 markers
- [x] Header comment explaining transport architecture → 9-line block
- [x] `manifest.json` post-install output updated → separate workers + placeholder reminder
- [x] `README.md` updated to match new transport names → 3 find-and-replace substitutions
- [x] All existing tests still pass → no test files changed

## Out of Scope

- **Test config** (`tests/Functional/config/test.yaml`) — already uses correct syntax and independent naming
- **Production PHP code** — no transport names referenced except `outbox` and `amqp`
- **Main `CLAUDE.md`** — references `amqp_orders` in documentation examples; update separately after merge
- **Main `README.md`** (root) — references `amqp_orders` in configuration examples; update in follow-up

## References

- Issue: #11
- Symfony Messenger config reference: `config/reference.php:458-462` (default_middleware nesting)
- Prior plan with serialiser assignment table: `docs/plans/2026-02-06-fix-recipe-serialiser-class-references-plan.md`
- Institutional learning on middleware registration: `docs/solutions/test-failures/deduplication-middleware-not-running-in-tests.md`
- Test config (reference for correct AMQP syntax): `tests/Functional/config/test.yaml:52-60`
