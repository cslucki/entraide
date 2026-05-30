---
task_id: TASK-175
title: Fix T0757 profile organization scoping test

status: IN_PROGRESS

owner: SUPERVISOR

contributors: []

branch: TASK-175-fix-t0757-profile-org-scoping

priority: HIGH

created_at: 2026-05-30 21:25:00 Europe/Paris
updated_at: 2026-05-30 21:25:00 Europe/Paris

labels: [fix, test, ci]

lock:
  status: UNLOCKED
  agent: SUPERVISOR
  since: 2026-05-30 21:27:00 Europe/Paris

handoff: false

pr:
status: MERGED
  url: null
---

# Objective

Fix the T0757 test that expects 404 when no organization is resolved but received 200.
Root cause: `User::factory()->create()` creates an Organization by default, which gets resolved by `ResolveUrlOrganization` middleware — preventing the expected 404.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [ ] validate UI (N/A — test-only change)

---

# Progress Log

## 2026-05-30 21:25:00 Europe/Paris

Fix applied: set `organization_id => null` in `test_profile_show_fails_without_organization`.
3/3 T0757 tests pass. Full suite: 824 passed, 11 skipped, 0 failures.

---

# Tests

- [x] T0757 specific test (3/3 passed)
- [x] Full suite (824 passed, 11 skipped)

---

# Test Results

```
Tests: 824 passed, 11 skipped (1749 assertions)
Duration: 27.14s
```
