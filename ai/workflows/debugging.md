# Laravel Debugging Workflow

## Goal

Debug issues safely while preserving:
- tenant isolation
- transaction consistency
- business integrity
- UI consistency

Avoid blind fixes.

---

# Standard Debugging Process

## 1. Understand the Problem

Before modifying code:

- identify expected behavior
- identify actual behavior
- identify impacted domains
- identify tenant implications
- identify transaction implications

Never implement fixes before understanding the root cause.

---

## 2. Inspect Logs

Prefer:

```bash
bat storage/logs/laravel.log
```

Inspect:

* stack traces
* SQL errors
* authorization failures
* Livewire errors

---

## 3. Search Related Code

Prefer:

```bash
rg "keyword"
```

Inspect:

* controllers
* policies
* routes
* models
* Livewire components
* middleware

---

## 4. Inspect Architecture

Use Filesystem MCP when available.

Understand:

* related models
* tenant scope
* policy flow
* transaction flow
* component relationships

Avoid isolated fixes without architecture understanding.

---

## 5. Verify UI Behavior

Use browser/MCP tools when available.

Inspect:

* browser console
* DOM state
* Livewire requests
* Alpine.js behavior
* validation errors

Do not assume frontend behavior without verification.

---

## 6. Implement Minimal Safe Fix

Prefer:

* smallest safe modification
* explicit logic
* maintainable solutions

Avoid:

* large rewrites
* unrelated refactors
* hidden side effects

---

## 7. Validate

After modifications:

* run relevant tests
* inspect tenant safety
* inspect policies
* verify transaction consistency
* verify UI behavior

---

# Critical Rule

Never modify:

* tenant logic
* transaction logic
* point system
* policies

without complete understanding of side effects.
