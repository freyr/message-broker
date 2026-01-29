# Compound Engineering Workflow Configuration

This project is configured to use the **Compound Engineering Plugin** workflow for all development work.

## Status: ✅ CONFIGURED

The workflow has been configured in `CLAUDE.md` and will be automatically applied to all future work in this repository.

## Workflow Commands Available

### Core Workflow (Use for all significant work)

1. **`/workflows:brainstorm`** - Explore requirements and approaches before planning
2. **`/workflows:plan`** - Create detailed implementation plans (80% of effort)
3. **`/workflows:work`** - Execute work systematically using worktrees (20% of effort)
4. **`/workflows:review`** - Run comprehensive multi-agent code reviews
5. **`/workflows:compound`** - Document learnings to compound team knowledge

### Supporting Commands

- **`/deepen-plan`** - Enhance plans with parallel research agents
- **`/plan_review`** - Multi-agent plan review in parallel
- **`/resolve_pr_parallel`** - Resolve PR comments in parallel
- **`/changelog`** - Create engaging changelogs for recent merges
- **`/triage`** - Triage and prioritise issues

## Available Review Agents

When running `/workflows:review`, these specialised agents are available:

### Language-Specific Reviewers
- `kieran-rails-reviewer` - Rails with strict conventions
- `kieran-python-reviewer` - Python with strict conventions
- `kieran-typescript-reviewer` - TypeScript with strict conventions
- `dhh-rails-reviewer` - Rails from DHH's perspective

### Specialised Reviewers
- `architecture-strategist` - Architectural decisions
- `performance-oracle` - Performance optimisation
- `security-sentinel` - Security audits
- `data-integrity-guardian` - Database migrations
- `pattern-recognition-specialist` - Patterns and anti-patterns
- `code-simplicity-reviewer` - Final simplicity pass
- `agent-native-reviewer` - Agent-native architecture verification

### Research Agents
- `framework-docs-researcher` - Framework documentation
- `best-practices-researcher` - External best practices
- `git-history-analyzer` - Code evolution analysis
- `repo-research-analyst` - Repository structure

## MCP Servers

The plugin includes the **Context7** MCP server for framework documentation:
- `resolve-library-id` - Find library ID for frameworks
- `get-library-docs` - Get documentation for libraries
- Supports 100+ frameworks (Rails, React, Symfony, Vue, Django, Laravel, etc.)

## Philosophy

> "Each unit of engineering work should make subsequent units easier—not harder."

- Plan first (80%), code second (20%)
- Compound knowledge with every task
- Multi-agent review for quality
- Document patterns for reusability

## When to Use

**Always use for:**
- New features
- Bug fixes (non-trivial)
- Refactoring
- Architecture changes
- Performance improvements

**Skip for:**
- Trivial typo fixes
- Simple dependency updates
- Emergency hotfixes (document after)

## Quick Start

For your next task, simply start with:

```
/workflows:brainstorm [describe what you want to build]
```

Or jump straight to planning if requirements are clear:

```
/workflows:plan [describe the implementation]
```

The workflow will guide you through the rest!
