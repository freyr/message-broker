---
status: pending
priority: p2
issue_id: 7
tags: [code-review, security, information-disclosure]
dependencies: []
---

# Reduce Information Disclosure in UUID Validation Errors

## Problem Statement

UUID validation error messages in `DeduplicationMiddleware` expose internal message class names and potentially sensitive UUID values to external systems, aiding attacker reconnaissance.

**Impact**:
- **Security concern** (medium severity)
- **Information leakage** enables targeted attacks
- **Internal structure exposure** reveals message routing logic

## Findings

**Security Sentinel Review** - Finding 2: "Information Disclosure in UUID Validation Exception"

**Location**: `src/Inbox/DeduplicationMiddleware.php:41-50`

**Current Code**:
```php
throw new \InvalidArgumentException(
    sprintf(
        'MessageIdStamp contains invalid UUID: "%s". %s (message class: %s)',
        $messageId,        // ⚠️ Exposes malformed UUID
        $e->getMessage(),  // ⚠️ Ramsey UUID internal error
        $envelope->getMessage()::class  // ⚠️ Exposes FQN
    ),
    0,
    $e
);
```

**Attack Vector**:
Attacker sends messages with malformed UUIDs to:
1. Enumerate internal message class structures (FQN patterns)
2. Understand message type mappings
3. Identify routing logic
4. Probe for validation weaknesses

## Proposed Solutions

### Solution 1: Generic Error + Internal Logging (RECOMMENDED)

**Effort**: Small (30 minutes)
**Risk**: Low
**Pros**:
- Prevents information leakage
- Maintains debugging capability via logs
- Industry best practice

**Cons**:
- Slightly harder to debug from external error alone (must check logs)

**Implementation**:
```php
// Log full details internally (monitoring/debugging)
$this->logger?->warning('Invalid UUID in MessageIdStamp', [
    'message_id' => $messageId,
    'message_class' => $envelope->getMessage()::class,
    'error' => $e->getMessage(),
]);

// Generic error message to external systems
throw new \InvalidArgumentException(
    'MessageIdStamp contains invalid UUID format',
    0,
    $e
);
```

### Solution 2: Redacted Error Messages

**Effort**: Small (20 minutes)
**Risk**: Low
**Pros**:
- Partial information for debugging
- Redacts sensitive parts

**Cons**:
- Still exposes some information
- More complex

**Implementation**:
```php
throw new \InvalidArgumentException(
    sprintf(
        'MessageIdStamp contains invalid UUID: %s',
        substr($messageId, 0, 8) . '...'  // Only first 8 chars
    ),
    0,
    $e
);
```

### Solution 3: Keep Current (Document Risk)

**Not recommended** - Information leakage is security anti-pattern

## Recommended Action

**Implement Solution 1**: Generic error message + comprehensive internal logging

## Technical Details

**Affected Files**:
- `src/Inbox/DeduplicationMiddleware.php` (modify exception message, add logging)

**Logger Injection**: Already exists in constructor (optional dependency)

**Logging Level**: WARNING (invalid input, not critical failure)

**Breaking Changes**:
- Exception message format changes (could affect log parsers)
- Migration: Update any log parsing that depends on specific message format

## Acceptance Criteria

- [ ] Exception message changed to generic "invalid UUID format"
- [ ] Full details logged via `$this->logger->warning()`
- [ ] Log includes: message_id, message_class, error
- [ ] Existing tests updated if they assert on exception message
- [ ] New test: Verify exception message doesn't leak FQN
- [ ] Security review confirms information disclosure reduced

## Work Log

_No work done yet_

## Resources

- **Review**: Security Sentinel Review - Finding 2
- **File**: `src/Inbox/DeduplicationMiddleware.php:41-50`
- **OWASP**: Information Leakage best practices
- **Related**: CLAUDE.md security guidelines
