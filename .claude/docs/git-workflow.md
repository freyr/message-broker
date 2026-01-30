# Git/GitHub Conventions

## Branch Naming

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

## Commit Message Conventions

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

## Pull Request Conventions

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

## Complete Workflow Example

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

## Benefits

1. **Automatic Issue Tracking:** GitHub links commits/PRs to issues automatically
2. **Automatic Issue Closing:** Issues close when PR merges (using Fixes/Closes/Resolves)
3. **Clear History:** Easy to trace code changes back to requirements
4. **Better Collaboration:** Team members can see what's being worked on
5. **Release Notes:** Conventional commits enable automated changelog generation

## Tools Integration

This convention works with:
- **GitHub Actions:** Auto-close issues on merge
- **Release Please:** Automated version bumping and changelogs
- **Semantic Release:** Automated releases based on commit messages
- **Git History:** `git log --oneline --grep="feat:"` to find features
