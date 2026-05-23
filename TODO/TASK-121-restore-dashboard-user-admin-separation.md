---
task_id: TASK-121
title: restore-dashboard-user-admin-separation (continuation)

status: DONE

owner: CODEX-COCKPIT

contributors: []

branch: TASK-121-restore-dashboard-user-admin-separation

priority: MEDIUM

created_at: 2026-05-23 10:15:00 Europe/Paris
updated_at: 2026-05-23 10:30:00 Europe/Paris

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

Fix 149 CSRF test failures (419 status) across the full Feature test suite, restore all 692 tests to green, and inject QA accounts into PostgreSQL for validating admin/member dashboard separation.

---

# Planned Actions

- [x] identify root cause of 419 CSRF failures
- [x] fix in base TestCase
- [x] run full Feature suite — 692 passed (1526 assertions)
- [x] validate AdminDashboardRedirectTest clean (no workaround)

---

# Progress Log

## 2026-05-23 10:15:00 Europe/Paris

Branch already exists from TASK-120 continuation.

Scope:

1. Seed QA accounts into PostgreSQL (QaAccountsSeeder) — 5 users injected.
2. Fix PostgreSQL table ownership (postgres → bouclepro) for all public tables.
3. Fix CSRF test failures across full Feature suite.

### CSRF Root Cause

- `withoutMiddleware(ValidateCsrfToken::class)` silently had no effect.
- Laravel 11 registers `PreventRequestForgery::class` as the middleware class in the web group pipeline, NOT `ValidateCsrfToken::class` (which extends PreventRequestForgery).
- Registering a no-op for `ValidateCsrfToken` in the container doesn't intercept resolution of `PreventRequestForgery`.
- Fixed by using `PreventRequestForgery::class` in the TestCase setUp.

### Scope Decisions

- bootstrap/app.php is clean (no testing env check added).
- AdminDashboardRedirectTest is clean (no CSRF token workaround or `withoutMiddleware` in test file).
- Single centralized fix in `tests/TestCase.php` covers all Feature tests.

---

# Tests

- [x] feature tests (692 pass, 1526 assertions)
- [x] CSRF regression: AdminDashboardRedirectTest (8/8 pass, clean file)

---

# Test Results

- `php artisan test --testsuite=Feature` — 692 passed, 1526 assertions, 93.88s.

---

# Review Notes

- Minimal change: 8 lines added to `tests/TestCase.php`.
- No code changes to controllers, middleware, routes, or views.
- No change to tenant isolation, Organization resolution, or production behavior.
- 419 errors were testing-only; PreventRequestForgery is not removed in production.
