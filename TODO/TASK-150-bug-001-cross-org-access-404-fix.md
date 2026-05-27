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

- [x] Fix ResolveUrlOrganization: `str_starts_with($segment, 'livewire-')` in `isFeatureRoute()`
- [x] Run `bug-001-cross-org-livewire.spec.js` — 1/1 PASS
- [x] Run PHPUnit Feature suite — 820/820 PASS
- [ ] Merge into TASK-148

---
# Progress Log


## 2026-05-27 19:29:00 Europe/Paris

Task created.

Owner: PROJECT_SUPERVISOR
Branch: TASK-150-bug-001-cross-org-access-404-fix
Status: IN_PROGRESS

## 2026-05-27 20:00:00 Europe/Paris

Fix applied.

- Added `str_starts_with($segment, 'livewire-')` return true in `isFeatureRoute()`
- This makes Livewire POST requests (`/livewire-{hash}/update`) resolve Default Org
- Root cause: Livewire segment not in any route list → falls through to `resolveFromAuthenticatedUser()` → BNI org ≠ Test org → 404

Playwright test `bug-001-cross-org-livewire.spec.js`:
- M1 (BNI) creates service under Default Org → ✅
- M2 proposes transaction → ✅
- M1 accesses /messages/{txId} → Accept button visible (no Livewire 404) → ✅
- M1 clicks Accept → transaction status changes → ✅

PHPUnit: 820/820 passed (0 regression).

# Handoffs

# Tests

- [ ] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [ ] tenant validation

---

# Test Results

| Test | Result |
|------|--------|
| `bug-001-cross-org-livewire.spec.js` | ✅ 1/1 PASS |
| PHPUnit Feature suite | ✅ 820/820 (1748 assertions) |

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