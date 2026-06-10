---
task_id: TASK-232
title: Fix CI — 11 test failures

status: MERGED

owner: OPENCODE

contributors: []

branch: TASK-232-fix-ci-11-test-failures

priority: MEDIUM

created_at: 2026-06-10 19:02:22 Europe/Paris
updated_at: 2026-06-10 19:02:22 Europe/Paris

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

Fix 11 CI test failures after UI refonte merge (TASK-228→231).

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [ ] ~~validate UI~~ (no UI changes needed)

---

# Progress Log


## 2026-06-10 19:02:22 Europe/Paris

Task created.

## 2026-06-10 Europe/Paris — Analysis Complete

3 failure groups identified:

1. **AdminMessagesTest (7 failures):** `LoopMessage` and `Message` created without `organization_id`. Controller filters by org but records had null → no match → 404/empty.

2. **LoopOrganizationModeTest (2 failures):** Controller rendered `loops.mono-setup-required` view instead of `loops.index` with `$noPrimaryLoopWarning`.

3. **T0755ServicesRequestsTenantSafetyTest (2 failures):** `CategoryFactory` creates an `Organization` in its factory definition. When tests expected "no org" scenario, middleware resolved the factory-created org instead of aborting 404.

## 2026-06-10 Europe/Paris — Fixes Applied

### Fix 1 — AdminMessagesTest
- `makeLoopMessage()`: added `'organization_id' => $loop->organization_id`
- `makeExchangeMessage()`: added `'organization_id' => $transaction->organization_id`
- `makeTransactionInOrg()`: removed duplicate `organization_id` key

### Fix 2 — LoopController
- Changed mono-loop-no-primary from `view('loops.mono-setup-required')` to `view('loops.index', ['loops' => collect(), 'canCreate' => false, 'noPrimaryLoopWarning' => true])`

### Fix 3 — T0755ServicesRequestsTenantSafetyTest
- Both "fails safe" tests now delete all orgs before POST (org was auto-created by CategoryFactory)
- Kept `tearDown()` cleanup for cross-test safety

## 2026-06-10 Europe/Paris — Full Suite Green

All 878 tests pass (1927 assertions, 53s).

Owner:
OPENCODE

Branch:
TASK-232-fix-ci-11-test-failures

Status:
IN_PROGRESS

# Handoffs

# Tests

- [x] feature tests
- [ ] ~~browser validation~~ (no UI changes)
- [ ] ~~responsive validation~~ (no UI changes)
- [ ] ~~console inspection~~ (no UI changes)
- [x] tenant validation (AdminMessages isolation tests green)

---

# Test Results

**Full suite: 878 passed, 0 failed (1927 assertions, 53s)**

- AdminMessagesTest: 18 ✓
- LoopOrganizationModeTest: 14 ✓
- T0755ServicesRequestsTenantSafetyTest: 6 ✓

---

# Review Notes

## Files modified
- `tests/Feature/Admin/AdminMessagesTest.php` — added org_id to test helpers
- `app/Http/Controllers/LoopController.php` — return loops.index with warning
- `tests/Feature/T0755ServicesRequestsTenantSafetyTest.php` — delete orgs before POST
- `TODO/TASK-232-fix-ci-11-test-failures.md` — progress update

## No regression risk
- All fixes are in test helpers or UI rendering path for edge case (mono-loop without primary)
- AdminMessages tests verify tenant isolation still works
- Full suite green

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`