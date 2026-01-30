# Compound Engineering Plugin Workflow

**CRITICAL: All engineering work in this project MUST follow this workflow.**

## Philosophy

> "Each unit of engineering work should make subsequent units easier—not harder."

- Emphasise planning over coding (80/20 rule)
- Compound knowledge with every completed task
- Use multi-agent review for quality assurance
- Document patterns for team learning

## Mandatory Process

For **ALL** new features, bug fixes, refactoring, or significant changes:

### 1. Brainstorm Phase - `/workflows:brainstorm`
- Explore requirements and approaches before planning
- Understand constraints and trade-offs
- Identify potential challenges

### 2. Planning Phase - `/workflows:plan`
- Create detailed implementation plans
- Break down work into concrete, actionable tasks
- Document technical approach and architecture decisions
- **80% of effort should go here** (not in coding)

### 3. Execution Phase - `/workflows:work`
- Execute work items systematically using worktrees
- Follow the plan created in step 2
- Track progress through task completion
- **Only 20% of effort should go here**

### 4. Review Phase - `/workflows:review`
- Conduct comprehensive multi-agent code review
- Use specialised reviewers for language/framework
- Address all feedback before merging

### 5. Documentation Phase - `/workflows:compound`
- Document learnings and patterns discovered
- Add to knowledge base for future reusability
- Make subsequent work easier

## Available Specialised Reviewers

When running `/workflows:review`, leverage these agents:

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

## Exception Handling

**When to skip the workflow:**
- Trivial documentation typo fixes
- Emergency hotfixes (document after the fact)
- Simple dependency updates

**For all other work:** Always start with `/workflows:brainstorm` or `/workflows:plan`.
