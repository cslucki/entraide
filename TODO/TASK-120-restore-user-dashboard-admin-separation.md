---
task_id: TASK-120
title: restore-user-dashboard-admin-separation

status: DONE

owner: CODEX

contributors: []

branch: TASK-120-restore-user-dashboard-admin-separation

priority: MEDIUM

created_at: 2026-05-23 09:39:27 Europe/Paris
updated_at: 2026-05-23 09:41:54 Europe/Paris

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

Restore the functional separation between the member dashboard at `/dashboard` and the admin dashboard at `/admin/dashboard`, without weakening Organization tenant resolution.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [x] validate UI

---
# Progress Log


## 2026-05-23 09:39:27 Europe/Paris

Task created.

Owner:
CODEX

Branch:
TASK-120-restore-user-dashboard-admin-separation

Status:
IN_PROGRESS

## 2026-05-23 09:41:54 Europe/Paris

Correction completed:
- Cause: the previous fix allowed exact `/dashboard` for tenantless admins in `ResolveUrlOrganization` and `DashboardController` rendered `admin.dashboard` when no Organization was resolved for an admin.
- Removed all `/dashboard` logic that renders `admin.dashboard`.
- Removed the admin-specific `/dashboard` middleware bypass so `/dashboard` remains Organization-scoped through the authenticated user's Organization.
- Updated admin login routing: admin with active Organization goes to `/dashboard`; tenantless admin goes to `/admin/dashboard`.
- Verified QA super-admin test account source: `TEST_ADMIN_LOGIN` uses the QA admin account, and `QaAccountsSeeder` attaches that account to the active `cpme` Organization.
- Updated feature tests to prove `/dashboard` renders the member dashboard and `/admin/dashboard` renders the admin dashboard as distinct surfaces.

Tenant decision:
- No tenantless member-dashboard access was introduced.
- Admin without Organization still cannot use `/dashboard`.
- `/admin/dashboard` remains the tenantless global admin surface.

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

- `php artisan test tests/Feature/Admin/AdminDashboardRedirectTest.php` — PASS, 8 tests, 17 assertions.
- `php artisan test tests/Feature/Admin/AdminDashboardRedirectTest.php tests/Feature/ResolveUrlOrganizationTest.php tests/Feature/T0754DashboardMembersExchangesTenantSafetyTest.php tests/Feature/T07411RoutesTenantSafetyTest.php` — PASS, 55 tests, 102 assertions.
- `php artisan test --testsuite=Feature` — PASS, 692 tests, 1526 assertions.
- Browser/responsive/console validation not run: no Blade, JavaScript, CSS, or browser interaction changed; assertions validate the rendered Laravel views.

---

# Review Notes

- Self-review completed by CODEX per user instruction to skip CAO for this corrective task.
- Scope is limited to dashboard/admin separation, login destination, and regression tests.
- `/dashboard` now resolves only through normal Organization tenant binding.
- `/dashboard` never renders `admin.dashboard`.
- `/admin/dashboard` remains accessible to admins, including tenantless admins.
