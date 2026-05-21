> **AGENT CONTEXT ONLY**
>
> This file is an operational summary for agents. It is not canonical project documentation. If this file conflicts with `docs/`, `docs/` wins.

# Testing Strategy

## Canonical Sources

- `docs/README.md`
- `docs/04-ENGINEERING_RULES.md`
- `ai/workflows/task-lifecycle.md`
- `ai/playwright/README.md`

Use this file as a validation checklist only.

## Critical Domains

The following systems require strong testing coverage:

- tenant isolation
- transactions
- point ledger
- policies
- APIs
- Livewire flows

---

# Preferred Tests

Prefer:
- feature tests
- integration tests
- business-flow tests
- policy tests

Avoid:
- brittle implementation-specific tests

---

# Validation Workflow

Before merge:
1. run relevant tests
2. validate tenant safety
3. validate transaction consistency
4. validate UI behavior
5. inspect logs if needed

---

# AI Testing Philosophy

Agents should:
- add tests for critical business logic
- preserve existing tests
- avoid removing protections
- validate edge cases

Critical business systems should never be modified without validation.
