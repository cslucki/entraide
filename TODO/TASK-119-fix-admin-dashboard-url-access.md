---
task_id: TASK-119
title: fix-admin-dashboard-url-access

status: DONE

owner: CODEX

contributors: []

branch: TASK-119-fix-admin-dashboard-url-access

priority: MEDIUM

created_at: 2026-05-23 09:21:07 Europe/Paris
updated_at: 2026-05-23 09:24:47 Europe/Paris

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

Restore `/dashboard` as the directly accessible URL for tenantless super-admin users while preserving Organization tenant safety for normal member dashboard access.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [x] validate UI

---
# Progress Log


## 2026-05-23 09:21:07 Europe/Paris

Task created.

Owner:
CODEX

Branch:
TASK-119-fix-admin-dashboard-url-access

Status:
IN_PROGRESS

## 2026-05-23 09:21:56 Europe/Paris

Initial correction:
- Created official task from `develop`.
- Pre-existing out-of-scope Vite artifact `public/build/manifest.json` was stashed with a targeted path-only stash before branch creation.
- Cause of T118 functional mismatch: `ResolveUrlOrganization` redirected exact `/dashboard` for admins to `admin.dashboard`, making `/dashboard` no longer the accessible admin URL.
- Replaced the middleware redirect with a pass-through for authenticated admins on exact `/dashboard` only.
- Updated `DashboardController` so admin users without a resolved Organization render the existing admin dashboard view at `/dashboard`.
- Updated admin login fallback to redirect to named `dashboard` instead of `admin.dashboard`.
- Adapted the dedicated feature test expectations.

Tenant decision:
- No default Organization is bound for admins.
- No tenantless access is added for normal members.
- `/dashboard/anything` remains outside this bypass.

## 2026-05-23 09:24:47 Europe/Paris

Validation completed:
- Dedicated admin dashboard test passes and confirms tenantless admin `GET /dashboard` returns 200 with no redirect.
- Routing and tenant-safety focused suites pass.
- Full Feature suite passes after middleware and login controller changes.
- Playwright was not run because no browser interaction or view markup changed; the rendered view is the existing admin dashboard view.

Status updated to DONE and lock released for official gate scripts.

# Handoffs

# Tests

- [x] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [x] tenant validation

---

# Test Results

- `php artisan test tests/Feature/Admin/AdminDashboardRedirectTest.php` — PASS, 6 tests, 9 assertions.
- `php artisan test tests/Feature/Admin/AdminDashboardRedirectTest.php tests/Feature/ResolveUrlOrganizationTest.php tests/Feature/T0754DashboardMembersExchangesTenantSafetyTest.php tests/Feature/T07411RoutesTenantSafetyTest.php` — PASS, 53 tests, 94 assertions.
- `php artisan test --testsuite=Feature` — PASS, 690 tests, 1518 assertions.
- Browser/responsive/console validation not run: no markup, asset, JavaScript, or browser interaction was changed.

---

# Review Notes

- Self-review completed by CODEX per user instruction not to launch CAO for this corrective task.
- Scope is limited to exact `/dashboard` admin handling, login fallback, and dedicated feature tests.
- No broad tenant bypass was introduced: normal users without Organization still receive 404 on `/dashboard`, admin `/dashboard/anything` remains 404, and route middleware still resolves Organization for business routes.
- `/admin/dashboard` remains accessible for admins.
