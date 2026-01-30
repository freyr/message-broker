---
status: pending
priority: p3
issue_id: 7
tags: [code-review, documentation, data-integrity, architecture]
dependencies: []
---

# Document Database Constraint Design Rationale

## Problem Statement

The database schema's constraint design decisions are correctly implemented but lack written rationale, making it unclear WHY certain choices were made (single-column PRIMARY KEY, no foreign keys, VARCHAR(255) for message_name).

**Impact**:
- Future developers question design decisions
- Architectural knowledge lost over time
- Risk of "fixing" intentional design

## Findings

**Data Integrity Review** identified excellent constraint implementation but recommended documenting design rationale:

### Undocumented Design Decisions:

1. **Single-column PRIMARY KEY** (message_id only)
   - Why not composite (message_id, message_name)?
   - Rationale: UUID v7 globally unique, message_name unnecessary for uniqueness

2. **No foreign keys** (intentional decoupling)
   - Why no FK to domain tables?
   - Rationale: Event-driven architecture, eventual consistency, deduplication is standalone

3. **VARCHAR(255) for message_name**
   - Why 255? Is it sufficient?
   - Rationale: PHP FQN typical length ~50-100 chars, 255 is safe buffer

4. **DATETIME precision** (not DATETIME(6))
   - Why second-level precision?
   - Rationale: Deduplication by message_id (not timestamp), cleanup queries don't need microseconds

## Proposed Solutions

### Solution 1: Add Section to database-schema.md (RECOMMENDED)

**Effort**: Small (1 hour to write, 30 min review)
**Risk**: None
**Pros**:
- Preserves architectural knowledge
- Prevents future "improvements" that break design
- Aids onboarding

**Cons**: None

**Implementation**:

Add to `docs/database-schema.md`:

````markdown
## Constraint Design Rationale

### Primary Key: Single Column (message_id)

**Decision**: Use `message_id BINARY(16) PRIMARY KEY` only, not composite `(message_id, message_name)`

**Rationale**:
- UUID v7 provides **global uniqueness** across all message types
- 2^122 possible UUIDs → collision probability negligible
- message_name indexed for queries but not uniqueness enforcement
- Composite key would be redundant (message_id alone guarantees uniqueness)

**Trade-off**: Theoretical risk of same UUID across different message types, but:
- UUID v7 generation ensures this never happens in practice
- Simplifies queries (single-column lookup)
- Reduces index size

### No Foreign Keys

**Decision**: No FK constraints to domain entities

**Rationale**:
- **Intentional architectural decoupling**
- Event-driven system with eventual consistency
- Deduplication table is standalone idempotency store
- External events may reference entities that don't exist yet
- No cascade delete requirements (entries cleaned via TTL)

**Trade-off**: Can't enforce referential integrity at DB level, but:
- Deduplication doesn't depend on entity existence
- Messages carry all necessary data (self-contained)
- Loose coupling enables independent scaling

### VARCHAR(255) for message_name

**Decision**: 255 character limit for PHP FQN

**Rationale**:
- Typical FQN length: 50-100 chars (e.g., `App\Domain\Event\OrderPlaced`)
- Longest realistic FQN: ~150 chars
- 255 provides safe buffer without waste
- MySQL VARCHAR efficiently stores actual length (no padding)

**Validation**: No truncation risk in practice

### DATETIME Precision (Second-Level)

**Decision**: DATETIME (not DATETIME(6) with microseconds)

**Rationale**:
- Deduplication based on message_id (UUID), not timestamp
- processed_at used for cleanup queries (day-level granularity)
- No ordering requirements within same second
- Microsecond precision unnecessary overhead

**Storage Savings**: 2 bytes per row (minor but measurable at scale)
````

### Solution 2: Inline Comments in Migration SQL

**Effort**: Small (30 minutes)
**Risk**: None
**Pros**:
- Documentation next to implementation

**Cons**:
- SQL comments easy to overlook
- Not as comprehensive as markdown doc

**Use in addition to Solution 1**, not instead.

## Recommended Action

**Implement Solution 1**: Add comprehensive "Constraint Design Rationale" section to database-schema.md

**Also**: Add brief inline SQL comments to migration file

## Technical Details

**Affected Files**:
- `docs/database-schema.md` (add new section, ~100 lines)
- `migrations/schema.sql` (add inline comments, optional)

**No Code Changes**: Documentation only

**Breaking Changes**: None

## Acceptance Criteria

- [ ] "Constraint Design Rationale" section added to database-schema.md
- [ ] Documents: single-column PK, no FKs, VARCHAR(255), DATETIME precision
- [ ] Each decision includes: Decision, Rationale, Trade-offs
- [ ] Cross-referenced from main schema documentation
- [ ] Reviewed by data integrity guardian (if available)
- [ ] Clear enough for new developers to understand WHY

## Work Log

_No work done yet_

## Resources

- **Review**: Data Integrity Review - Section 9 "Database Constraints Documentation"
- **File**: `docs/database-schema.md`
- **Related**: Architecture documentation explaining event-driven design
