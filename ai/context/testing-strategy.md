# Testing Strategy

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