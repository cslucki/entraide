---
task_id: TASK-118
title: fix-admin-dashboard-404-redirect

status: DONE

owner: CODEX

contributors: []

branch: TASK-118-fix-admin-dashboard-404-redirect

priority: MEDIUM

created_at: 2026-05-23 08:44:36 Europe/Paris
updated_at: 2026-05-23 08:58:36 Europe/Paris

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

Fix the authenticated super-admin `/dashboard` 404 by redirecting admin users to the existing admin surface, without weakening Organization tenant scoping for normal business routes.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [x] validate UI

---
# Progress Log


## 2026-05-23 08:44:36 Europe/Paris

Task created.

Owner:
CODEX

Branch:
TASK-118-fix-admin-dashboard-404-redirect

Status:
IN_PROGRESS

## 2026-05-23 08:44:56 Europe/Paris

Initial setup:
- Created official task branch from clean `develop`.
- Pre-existing out-of-scope Vite artifact `public/build/manifest.json` was stashed before branch creation using targeted path-only stash.
- CAO is installed; `cao session list` initially reported the CAO server was not running.
- Next: launch CAO `laravel_developer` worker to inspect `/dashboard` super-admin 404 cause and minimal fix.

## 2026-05-23 08:52:45 Europe/Paris

Implementation completed:
- Launched CAO HTTP server locally and assigned `laravel_developer` inspection worker.
- CAO `laravel_developer` confirmed `/dashboard` is Organization-scoped, `/admin/dashboard` is the valid admin target, and `/admin` has no declared route.
- Root cause: tenantless admin users hit `ResolveUrlOrganization` for `/dashboard`; no `community_id` means no `current_organization`, so the request fail-closes with 404 before member dashboard rendering.
- Added a narrow admin-only `/dashboard` redirect in `ResolveUrlOrganization` to `route('admin.dashboard')`.
- Added a tenantless-admin login fallback to `admin.dashboard`.
- Added feature coverage for admin redirect, valid admin destination, member dashboard unchanged, tenantless non-admin still 404, and tenantless admin login fallback.

Modified files:
- `app/Http/Middleware/ResolveUrlOrganization.php`
- `app/Http/Controllers/Auth/AuthenticatedSessionController.php`
- `tests/Feature/Admin/AdminDashboardRedirectTest.php`
- `TODO/TASK-118-fix-admin-dashboard-404-redirect.md`

Tenant decision:
- Did not bind a default Organization for admins.
- Did not make `/dashboard` tenantless.
- Did not change tenant resolution for other business routes.

Tests:
- `php artisan test tests/Feature/Admin/AdminDashboardRedirectTest.php` — passed, 5 tests / 8 assertions.
- `php artisan test tests/Feature/ResolveUrlOrganizationTest.php tests/Feature/T0754DashboardMembersExchangesTenantSafetyTest.php tests/Feature/T07411RoutesTenantSafetyTest.php tests/Feature/Admin/AdminDashboardRedirectTest.php` — passed, 52 tests / 93 assertions.

Next:
- Run CAO review worker before finalization.

## 2026-05-23 08:58:36 Europe/Paris

Review and final validation:
- CAO review initially returned `RESERVE`: admin redirect matched `/dashboard/*` because the middleware checked only `segment(1)`.
- Fixed reserve by changing the guard to exact `request->path() === 'dashboard'`.
- Added regression test: `/dashboard/anything` for tenantless admin remains 404 and is not redirected to admin.
- CAO follow-up review returned `FAVORABLE`.
- Re-ran full Feature suite after the reserve fix.
- Playwright/browser validation not run: no frontend UI rendering or browser interaction changed; behavior is covered by HTTP feature tests.

Final tests:
- `php artisan test tests/Feature/Admin/AdminDashboardRedirectTest.php` — passed, 6 tests / 9 assertions.
- `php artisan test tests/Feature/ResolveUrlOrganizationTest.php tests/Feature/T0754DashboardMembersExchangesTenantSafetyTest.php tests/Feature/T07411RoutesTenantSafetyTest.php tests/Feature/Admin/AdminDashboardRedirectTest.php` — passed, 53 tests / 94 assertions.
- `php artisan test --testsuite=Feature` — passed, 690 tests / 1518 assertions.

Status:
DONE

Lock:
UNLOCKED

# Handoffs

# Tests

- [x] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [x] tenant validation

---

# Test Results

2026-05-23 08:52:45 Europe/Paris

- `php artisan test tests/Feature/Admin/AdminDashboardRedirectTest.php` — passed, 5 tests / 8 assertions.
- `php artisan test tests/Feature/ResolveUrlOrganizationTest.php tests/Feature/T0754DashboardMembersExchangesTenantSafetyTest.php tests/Feature/T07411RoutesTenantSafetyTest.php tests/Feature/Admin/AdminDashboardRedirectTest.php` — passed, 52 tests / 93 assertions.

2026-05-23 08:58:36 Europe/Paris

- `php artisan test tests/Feature/Admin/AdminDashboardRedirectTest.php` — passed, 6 tests / 9 assertions.
- `php artisan test tests/Feature/ResolveUrlOrganizationTest.php tests/Feature/T0754DashboardMembersExchangesTenantSafetyTest.php tests/Feature/T07411RoutesTenantSafetyTest.php tests/Feature/Admin/AdminDashboardRedirectTest.php` — passed, 53 tests / 94 assertions.
- `php artisan test --testsuite=Feature` — passed, 690 tests / 1518 assertions.
- Playwright/browser validation not run because no browser UI surface changed; redirect behavior is validated by feature tests.

---

# Review Notes

2026-05-23 08:58:36 Europe/Paris

CAO `laravel_developer` inspection:
- Cause confirmed: `/dashboard` is Organization-scoped and tenantless admin users fail 404 before dashboard rendering.
- Valid target confirmed: `/admin/dashboard` / `admin.dashboard`; `/admin` route is not declared.
- Recommended not binding default Organization and not making `/dashboard` tenantless.

CAO review:
- Initial verdict: `RESERVE` because the redirect guard matched `/dashboard/*`.
- Reserve fixed with exact path matching and regression test.
- Follow-up verdict: `FAVORABLE`.
