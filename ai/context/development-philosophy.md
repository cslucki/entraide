> **AGENT CONTEXT ONLY**
>
> This file is an operational summary for agents. It is not canonical project documentation. If this file conflicts with `docs/`, `docs/` wins.

# Development Philosophy

## Canonical Sources

- `docs/README.md`
- `docs/02-WORKFLOW_AND_ENGINEERING_PRINCIPLES.md`
- `docs/04-ENGINEERING_RULES.md`
- `ai/workflows/task-lifecycle.md`

Use this file as an engineering behavior reminder only.

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
