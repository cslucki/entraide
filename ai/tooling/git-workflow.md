# Development Philosophy

## Core Principles

Prefer:
- maintainability
- explicitness
- predictable behavior
- minimal safe changes
- readability
- business integrity

Avoid:
- premature abstraction
- unnecessary complexity
- magic behavior
- uncontrolled refactors
- hidden side effects

---

# Laravel Philosophy

Prefer:
- Laravel conventions
- thin controllers
- reusable services
- policy-driven authorization
- explicit validation

Avoid:
- duplicated business logic
- massive controllers
- unsafe static helpers
- tightly coupled components

---

# Refactoring Rules

Before refactoring:
1. understand business implications
2. inspect architecture
3. inspect tests
4. minimize blast radius

Prefer small incremental refactors.

---

# AI Agent Philosophy

Agents should:
- think before coding
- inspect before modifying
- validate before merging
- preserve architectural consistency

Never optimize blindly.

---

# Version Management

**Source of truth:** `VERSION` file at project root.

**Flow:**
1. Footer displays `config('app.version')`
2. `config('app.version')` reads from `VERSION` file
3. `ai/scripts/merge-task.sh` calls `bump-version.sh` after merge
4. `bump-version.sh` writes `v0.{TASK_ID}-alpha` to `VERSION`

**Agent rules:**
- Do NOT edit `VERSION` manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time