---
task_id: TASK-066
title: organization-runtime-compatibility-layer

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-066-organization-runtime-compatibility-layer

priority: MEDIUM

created_at: 2026-05-12 18:21:07 Europe/Paris
updated_at: 2026-05-12 19:10:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Create a SAFE and CENTRALIZED runtime compatibility layer for Organization resolution.

The duplicated pattern `app()->bound('current_organization') ? app('current_organization') : (app()->bound('current_community') ? app('current_community') : null)` existed in 3 files and has been centralized into a single support class + global helper function.

---

# Architecture

## Created

- `app/Support/Tenancy/CurrentOrganization.php` — centralized resolver with `current()` and `id()` static methods
- `app/Support/helpers.php` — global `currentOrganization()` helper function

## Modified

- `composer.json` — registered `app/Support/helpers.php` in autoload.files

## Updated (adopted centralized helper)

- `app/Http/Controllers/HomeController.php` — replaced 6-line `currentCommunityId()` with `currentOrganization()?->id`
- `app/Http/Controllers/Auth/RegisteredUserController.php` — replaced 3-line resolution with `currentOrganization()`
- `app/Livewire/Explorer.php` — replaced 4-line `mount()` resolution with `currentOrganization()?->id`

## Not Modified (intentionally)

- `app/Models/Scopes/BelongsToTenantScope.php` — already has clean private `resolveOrganization()`, left as-is for minimal diff
- `app/Http/Middleware/ResolveCommunity.php` — untouched, still binds both `current_organization` and `current_community`
- No routes, UI, Playwright selectors, database schema, or tenant isolation logic were touched

---

# Resolution Logic (preserved 1:1)

```php
CurrentOrganization::current()
// 1. prefers app('current_organization') — canonical
// 2. falls back to app('current_community') — legacy
// 3. returns null when neither is bound — console/admin/tests
```

---

# Progress Log

## 2026-05-12 18:21:07 Europe/Paris

Task created.

## 2026-05-12 18:43:00 Europe/Paris

Implementation complete — TASK-066 initial build:

1. Inspected all current_organization / current_community usage across app/
2. Identified 4 locations — 3 duplicated patterns + 1 clean private method (BelongsToTenantScope)
3. Created app/Support/Tenancy/CurrentOrganization.php with static get(), id(), resolved()
4. Created app/Support/helpers.php with currentOrganization() global function
5. Registered autoload.files in composer.json
6. Updated 3 consuming files to use currentOrganization()
7. Ran composer dump-autoload — success
8. Ran vendor/bin/pint --dirty — passed
9. Ran php artisan test — 294 passed, 597 assertions, 0 failures

## 2026-05-12 19:10:00 Europe/Paris

Refinement follow-up applied:

1. **Autoload verification** — composer.json is correct (autoload.files, not autoload-dev.files; no unsafe requires; no duplicates). ✅
2. **Method rename** — `CurrentOrganization::current()` → `CurrentOrganization::get()` to avoid awkward "CurrentOrganization::current()" naming.
3. **Added `resolved()`** — alias for `get()`, providing clear API semantics.
4. **Helper simplified** — `helpers.php` now contains ZERO logic (pure delegation to `CurrentOrganization::get()`), no type hints, no docblocks.
5. **Resolution logic unchanged** — identical bound() check chain preserved.
6. **Zero caller changes** — `currentOrganization()` helper signature unchanged; callers unaffected.
7. **Validated** — composer dump-autoload ✓, pint ✓, 294 tests passed ✓.

# Handoffs

None required — self-contained compat layer.

# Tests

- [x] feature tests (294 passed, 597 assertions)
- [ ] browser validation (Playwright)
- [x] pint lint
- [x] tenant resolution logic preserved identically

---

# Test Results

PHPUnit: 294 passed (597 assertions) — 0 failures, 0 errors.

---

# Review Notes

- Minimal surface area: +27 lines (new), -12 lines (removed), +3 lines (composer.json)
- No architecture drift — the resolution logic is exactly preserved
- Backward compatible — `current_community` binding still works via fallback
- No breaking changes to routes, UI, Playwright selectors, or middleware
- `CurrentOrganization` class is self-contained and easily testable
