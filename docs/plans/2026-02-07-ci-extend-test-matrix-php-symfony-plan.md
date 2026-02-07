    ---
title: "ci: Extend test matrix to cover PHP 8.2+ and Symfony 6.4"
type: ci
date: 2026-02-07
issue: 12
brainstorm: docs/brainstorms/2026-02-07-ci-matrix-php-symfony-brainstorm.md
reviewed: 2026-02-07 (DHH, Kieran, Simplicity)
---

# ci: Extend test matrix to cover PHP 8.2+ and Symfony 6.4

## Overview

Expand the CI workflow from a single PHP 8.4 / prefer-stable configuration to a matrix covering PHP 8.2–8.4 with Symfony 6.4 and 7.x. This ensures the declared compatibility ranges in `composer.json` are actually tested.

Fixes #12

## Problem Statement

The `composer.json` declares `"php": ">=8.2"` and `"symfony/*": "^6.4|^7.0"`, but CI only tests PHP 8.4 with Symfony 7.x. Users installing on PHP 8.2/8.3 or Symfony 6.4 have no confidence the package works for them.

## Prerequisite

**`freyr/identity` 0.4.0 must be released first** (handled separately). All existing versions (0.1.0–0.3.0) require `"php": "^8.4"`, so PHP 8.2/8.3 matrix combinations will fail until a compatible release exists.

## Implementation

One PR touching two files: `composer.json` and `.github/workflows/tests.yml`.

### 1. Update `composer.json`

| Package | Current | Target | Reason |
|---------|---------|--------|--------|
| `freyr/identity` | `^0.2 \| ^0.3` | `^0.4` | Only 0.4+ supports PHP 8.2. Older versions require ^8.4 and will never install on 8.2/8.3 — dead weight. |
| `phpunit/phpunit` | `^12.0` | `^11.0\|^12.0` | PHPUnit 12 requires PHP >=8.3; PHPUnit 11 supports >=8.2 |
| `symfony/framework-bundle` | `^7.0` | `^6.4\|^7.0` | Enable Symfony 6.4 matrix |
| `symfony/yaml` | `^7.0` | `^6.4\|^7.0` | Enable Symfony 6.4 matrix |
| `symfony/phpunit-bridge` | `^7.0` | `^6.4\|^7.0` | Enable Symfony 6.4 matrix |

**Pre-flight checks (before the PR):**
- Verify `symfony/phpunit-bridge` 6.4 supports PHPUnit 11. If not, consider removing the bridge or making it optional.
- Check existing tests for PHPUnit 12-only API usage (data providers, attributes) that would break on PHPUnit 11.

### 2. Update `.github/workflows/tests.yml`

**Matrix:**

```yaml
strategy:
  fail-fast: false
  matrix:
    php: ['8.2', '8.3', '8.4']
    symfony: ['6.4.*', '7.*']
    stability: ['prefer-stable']
    include:
      - php: '8.2'
        symfony: '6.4.*'
        stability: 'prefer-lowest'
```

7 combinations (3×2 + 1 prefer-lowest). The `prefer-lowest` on PHP 8.2 + Symfony 6.4 tests the absolute floor of the constraint space.

**Job name** (include Symfony version):
```yaml
name: PHP ${{ matrix.php }} - Symfony ${{ matrix.symfony }} - ${{ matrix.stability }}
```

**Symfony version locking** (add before `composer update`):
```yaml
- name: Lock Symfony version
  run: |
    composer global config --no-plugins allow-plugins.symfony/flex true
    composer global require --no-interaction symfony/flex
    composer config extra.symfony.require "${{ matrix.symfony }}"
```

Note: the `allow-plugins` line is required — without it Composer 2.2+ prompts interactively and CI hangs.

**Fix Composer cache key** (current key hashes `composer.lock` which doesn't exist for a library):
```yaml
- uses: actions/cache@v4
  with:
    path: ${{ steps.composer-cache.outputs.dir }}
    key: ${{ runner.os }}-php-${{ matrix.php }}-symfony-${{ matrix.symfony }}-composer-${{ hashFiles('**/composer.json') }}
    restore-keys: ${{ runner.os }}-php-${{ matrix.php }}-composer-
```

**Test Summary** (add Symfony version):
```yaml
echo "- Symfony Version: ${{ matrix.symfony }}" >> $GITHUB_STEP_SUMMARY
```

ECS and PHPStan jobs are unchanged (PHP 8.4 only).

**Important:** Do not commit `extra.symfony.require` to `composer.json` — CI adds it dynamically. If added during local testing, revert before committing.

## Acceptance Criteria

- [ ] `freyr/identity` 0.4.0 released with PHP >=8.2 support
- [x] `composer.json` constraints widened (see table above)
- [x] CI matrix covers PHP 8.2, 8.3, 8.4 × Symfony 6.4, 7.x + prefer-lowest
- [ ] All 7 matrix combinations pass
- [x] `extra.symfony.require` not committed to `composer.json`

## Out of Scope

- **`IdType` dead code** (`getName()`, `requiresSQLCommentHint()`): harmless on DBAL 4, still needed on DBAL 3. Address when DBAL 3 support is dropped.
- **`doctrine/doctrine-bundle ^3.0`**: version does not exist yet. Add when it is released.

## References

- **Brainstorm:** `docs/brainstorms/2026-02-07-ci-matrix-php-symfony-brainstorm.md`
- **Critical patterns:** `docs/solutions/patterns/critical-patterns.md` (schema setup must stay in test bootstrap)
- **GitHub issue:** #12
- **TestKernel:** `tests/Functional/TestKernel.php` (already handles PHP 8.2–8.3 vs 8.4 Doctrine lazy object strategy)
- **Current CI:** `.github/workflows/tests.yml`
