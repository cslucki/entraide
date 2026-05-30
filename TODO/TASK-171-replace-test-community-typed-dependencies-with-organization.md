---
task_id: TASK-171
title: Replace test Community typed dependencies with Organization

status: DONE

owner: SUPERVISOR

contributors: []

branch: TASK-171-replace-test-community-typed-dependencies-with-organization

priority: MEDIUM

created_at: 2026-05-30 08:52:56 Europe/Paris
updated_at: 2026-05-30 08:52:56 Europe/Paris

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

Describe the objective.

---

# Planned Actions

- [ ] inspect architecture
- [ ] inspect impacted files
- [ ] implement changes
- [ ] run tests
- [ ] validate UI

---
# Progress Log


## 2026-05-30 08:52:56 Europe/Paris

Task created.

## 2026-05-30 08:55:00 Europe/Paris

- Identified 18 test files importing `use App\Models\Community`
- Found 14 typed properties `private Community $xxx` in 10 files
- All 14 assigned from `Organization::factory()` — clear replacement candidates
- Added 3 files with Community type hints in helper method signatures
- Edited 13 files total:
  10 typed-property files (LoopActivityTracking, LoopHelpRequest, LoopMessage, LoopVisibilityMembership, LoopCreation, LoopMemberInvariant, T07411RoutesTenantSafety, Referral, T1392LegacyCharacterization, T1392RouteSmokeGates)
  3 helper-signature files (T0754Dashboard, T0755Services, T0756Blog)
- Target tests: 233/233 passed
- Full suite: 825 passed, 11 skipped — zero regression
- Confirmed: 0 files outside `tests/` modified
- T1392LegacyCharacterizationTest: kept Community import for lines 357/367 that explicitly test Community model interop

Owner:
SUPERVISOR

Branch:
TASK-171-replace-test-community-typed-dependencies-with-organization

Status:
IN_PROGRESS

# Handoffs

# Tests

- [x] feature tests — 233 targeted + 825 full suite, zero regression
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [ ] tenant validation

---

# Test Results

Pending.

---

# Review Notes

Pending.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`