# Review Process

## Goal

Review code changes critically before merge.

Focus on:
- maintainability
- business integrity
- tenant safety
- security
- performance
- architectural consistency

---

# Review Workflow

## 1. Inspect Git Diff

Prefer:
- small diffs
- focused changes
- isolated modifications

Avoid:
- unrelated rewrites
- formatting noise
- hidden side effects

---

## 2. Inspect Architecture Impact

Review:
- impacted domains
- policies
- routes
- transactions
- tenant implications
- Livewire implications

---

## 3. Verify Business Integrity

Critical systems:
- point ledger
- transactions
- tenant isolation
- messaging
- reviews

Must remain coherent after changes.

---

# Security Review

Inspect:
- authorization
- policies
- validation
- mass assignment
- unsafe queries
- tenant leaks

---

# Performance Review

Inspect:
- N+1 queries
- eager loading
- polling frequency
- query duplication
- unnecessary renders

---

# UI Review

Verify:
- browser behavior
- responsive layout
- validation states
- loading states
- console errors

Use browser tools when available.

---

# Testing Review

Verify:
- relevant tests exist
- tests still pass
- critical flows remain safe

Critical domains require tests.

---

# Merge Validation

Before merge:
- inspect final diff
- verify no secrets committed
- verify no unrelated files changed
- verify tenant consistency
- verify architectural consistency

---

# Review Philosophy

Prefer:
- maintainability
- explicitness
- predictable behavior
- small safe changes

Avoid:
- clever abstractions
- uncontrolled refactors
- architecture drift