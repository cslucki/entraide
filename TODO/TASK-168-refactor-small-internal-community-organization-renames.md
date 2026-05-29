---
task_id: TASK-168
title: Refactor: small internal Community→Organization renames

status: DONE

owner: SUPERVISOR

contributors: []

branch: TASK-168-refactor-small-internal-community-organization-renames

priority: MEDIUM

created_at: 2026-05-29 21:43:14 Europe/Paris
updated_at: 2026-05-29 21:43:14 Europe/Paris

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


## 2026-05-29 21:43:14 Europe/Paris

Task created.

Owner:
SUPERVISOR

Branch:
TASK-168-refactor-small-internal-community-organization-renames

Status:
IN_PROGRESS

## 2026-05-29 21:48:00 Europe/Paris

Implementation complete:
- helpers.php: removed community param fallback in organizationRoute()
- AdminController.php: assignCommunity() → assignOrganization()
- routes/web.php: updated method reference
- AdminUsersTest.php: Pint formatting

All 825 tests passed (0 regression).
Pint applied.

Status:
DONE

# Handoffs

# Tests

- [x] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [ ] tenant validation

---

# Test Results

825 passed, 11 skipped. Zero regression.

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