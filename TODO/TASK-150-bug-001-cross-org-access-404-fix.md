---
task_id: TASK-150
title: BUG-001 cross-org access 404 fix

status: IN_PROGRESS

owner: PROJECT_SUPERVISOR

contributors: []

branch: TASK-150-bug-001-cross-org-access-404-fix

priority: MEDIUM

created_at: 2026-05-27 19:29:00 Europe/Paris
updated_at: 2026-05-27 19:29:00 Europe/Paris

labels: []

lock:
  status: LOCKED
  agent: PROJECT_SUPERVISOR
  since: 2026-05-27 19:29:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Fix BUG-001: cross-org message access 404 on root domain.

When Livewire sends POST to /livewire-{hash}/update, the segment is not
in any allowed route list ($defaultOrganizationRoutes, $platformGlobalExact,
$platformGlobalPrefixes). resolveOrganization() falls through to
resolveFromAuthenticatedUser() → returns user's org (BNI) instead of
Default Org (Test community). BelongsToTenantScope filters by BNI org →
Transaction (Test community) → 404 in Livewire AJAX.

---

# Scope

Strict BUG-001 only:

1. Add 'livewire' to $defaultOrganizationRoutes in ResolveUrlOrganization
2. Transaction full-cycle test (t146) passes without graceful skip
3. t146 regression: 37/37 pass
4. No changes to t146 tests, t147, t148, or unrelated files

---

# Planned Actions

- [ ] Fix ResolveUrlOrganization: add 'livewire' to $defaultOrganizationRoutes
- [ ] Run t146 full-cycle transaction test (remove graceful skip workaround)
- [ ] Run all t146 tests (37/37 must pass)
- [ ] Run PHPUnit Feature suite (820+ pass)
- [ ] Validate browser: manual M1 login → accept M2 proposal → confirm

---
# Progress Log


## 2026-05-27 19:29:00 Europe/Paris

Task created.

Owner:
PROJECT_SUPERVISOR

Branch:
TASK-150-bug-001-cross-org-access-404-fix

Status:
IN_PROGRESS

# Handoffs

# Tests

- [ ] feature tests
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