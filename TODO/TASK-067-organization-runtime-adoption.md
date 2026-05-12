---
task_id: TASK-067
title: organization-runtime-adoption

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-067-organization-runtime-adoption

priority: MEDIUM

created_at: 2026-05-12 18:30:52 Europe/Paris
updated_at: 2026-05-12 18:42:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Adopt the centralized `CurrentOrganization` runtime resolver in tenant-aware runtime code, reducing duplicated resolution logic and standardizing Organization runtime access.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [ ] validate UI

---

# Progress Log

## 2026-05-12 18:30:52 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-067-organization-runtime-adoption

Status:
IN_PROGRESS

## 2026-05-12 18:42:00 Europe/Paris

Implementation complete.

- Searched all app/ PHP files for duplicated bound/fallback resolution patterns
- Found only one: `BelongsToTenantScope::resolveOrganization()` — an exact duplicate of `CurrentOrganization::get()`
- Delegated `resolveOrganization()` to `CurrentOrganization::get()`, removed 11 lines of duplicated logic
- All 294 tests pass
- No pint issues introduced
- No database, middleware, routes, Blade, or Playwright changes

Status: DONE

# Handoffs

# Tests

- [x] feature tests (294 passed, 597 assertions)
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [x] tenant validation (8 BelongsToTenantScope tests passed)

---

# Test Results

```
Tests:    294 passed (597 assertions)
Duration: 6.71s
```

---

# Modified Files

- `app/Models/Scopes/BelongsToTenantScope.php` — delegated to `CurrentOrganization::get()`

---

# Review Notes

- Only one duplicated resolution pattern existed in production code
- Change is a pure delegation with 100% behavioral equivalence
- All tenant isolation scenarios validated:
  - current_organization takes precedence
  - current_community works as legacy fallback
  - neither bound = no scope applied
  - Organization instances work identically to Community
- No architectural risks
- Runtime resolution now fully centralized

---

# Review Notes

Pending.