# Brainstorm: Extend CI Test Matrix (Issue #12)

**Date:** 2026-02-07
**Status:** Ready for planning
**Issue:** [#12 — ci: extend test matrix to cover PHP 8.2+ and Symfony 6.4](https://github.com/freyr/message-broker/issues/12)

## What We're Building

Extend the CI workflow to test across the full range of supported PHP versions (8.2, 8.3, 8.4) and Symfony versions (6.4, 7.x), ensuring the package works reliably for all declared compatibility ranges.

## Current State

- PHP requirement already lowered to `>=8.2` (commit b54a5c5)
- CI only tests PHP 8.4 + `prefer-stable`
- `require-dev` Symfony deps pinned to `^7.0` — blocks Symfony 6.4 matrix
- PHPUnit pinned to `^12.0` — may conflict with Symfony 6.4 PHPUnit Bridge

## Approach

### 1. Widen `require-dev` version constraints

| Package | Current | Target |
|---------|---------|--------|
| `symfony/framework-bundle` | `^7.0` | `^6.4\|^7.0` |
| `symfony/yaml` | `^7.0` | `^6.4\|^7.0` |
| `symfony/phpunit-bridge` | `^7.0` | `^6.4\|^7.0` |
| `phpunit/phpunit` | `^12.0` | `^11.0\|^12.0` |
| `doctrine/doctrine-bundle` | `^2.0` | `^2.0\|^3.0` |

### 2. CI matrix configuration

```yaml
matrix:
  php: ['8.2', '8.3', '8.4']
  symfony: ['6.4.*', '7.*']
  stability: ['prefer-stable']
  include:
    - php: '8.2'
      symfony: '6.4.*'
      stability: 'prefer-lowest'
```

Use `symfony/flex` to lock Symfony version per matrix combination.

### 3. ECS and PHPStan jobs

No changes — remain on PHP 8.4 only. Static analysis results are version-independent for this codebase.

## Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Dev deps version range | Widen to `^6.4\|^7.0` | Required for Symfony 6.4 matrix to install |
| PHPUnit version | `^11.0\|^12.0` | PHPUnit Bridge compatibility with Symfony 6.4 |
| `prefer-lowest` | PHP 8.2 + Symfony 6.4 only | Catches minimum constraint issues without bloating matrix |
| `doctrine/doctrine-bundle` | Widen to `^2.0\|^3.0` | Future-proof for DoctrineBundle 3.x |
| Static analysis jobs | No changes | Version-independent, stay on PHP 8.4 |

## Discovered During Planning

- **Blocker:** `freyr/identity` requires PHP ^8.4 (all versions). Must release 0.4.0 with PHP >=8.2 first.
- **PHPUnit 12** requires PHP >=8.3 (confirmed). PHPUnit 11 requires >=8.2.
- **Composer cache key** uses `composer.lock` hash but this is a library with no lockfile — needs fixing.
- **`IdType`** has dead methods (`getName`, `requiresSQLCommentHint`) removed in DBAL 4 — harmless but vestigial.
- **Symfony Flex:** Use `composer config extra.symfony.require` approach (not env var) for reliability.

## Open Questions

None — ready for planning.

## Next Steps

Run `/workflows:plan` to create the implementation plan.
