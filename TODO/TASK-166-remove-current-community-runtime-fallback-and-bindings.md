---
task_id: TASK-166
title: Remove current_community runtime fallback and bindings

status: DONE

owner: SUPERVISOR

contributors: []

branch: TASK-166-remove-current-community-runtime-fallback-and-bindings

priority: HIGH

created_at: 2026-05-29 19:17:29 Europe/Paris
updated_at: 2026-05-29 19:45:00 Europe/Paris

labels:
  - migration
  - cleanup
  - community→org
  - runtime

lock:
  status: UNLOCKED
  agent: none
  since: null

handoff: false

pr:
  status: NOT_READY
  url: null
---

# TASK-166 — Remove `current_community` runtime fallback and bindings

## Objective

Remove all runtime bindings and fallbacks for `current_community` so that `current_organization` is the sole runtime tenant context.

## Architecture Rules

- **Organization = Tenant.** Single security boundary.
- **Loop ≠ Tenant.** Loop is a collaborative group inside an Organization.
- **Partner ≠ Tenant.** Partner is a co-branding/distribution entry.
- **`current_organization` = source of truth** for runtime tenant context.
- **`current_community`** = legacy temporary compatibility — must NOT be bound as runtime tenant context.
- `community_id` may remain as a DB column for legacy compatibility.
- `Community` model may remain as technical compatibility base.
- `Organization extends Community` must continue to function.

## Scope (authorized files)

| File | Action |
|------|--------|
| `app/Http/Middleware/ResolveCommunity.php` | Remove `current_community` binding |
| `app/Http/Middleware/ResolveUrlOrganization.php` | Remove `current_community` fallback/binding |
| `app/Http/Middleware/ResolveApiOrganization.php` | Remove `current_community` fallback/binding |
| `app/Models/Traits/HasOrganizationId.php` | Remove `current_community` fallback if present |
| `app/Support/Tenancy/CurrentOrganization.php` | If strictly necessary |
| tests/ | Only directly related to current_community/currentOrganization |

## Constraints (forbidden)

- No DB migrations
- No DB structure changes
- No model renaming (Community stays)
- No `Organization extends Community` breakage
- No route changes
- No controller business logic changes
- No seeder/factory changes
- No UI changes
- No massive Community removal
- No commit without passing tests
- Do NOT merge without Cyril validation
- Do NOT touch `ai-local/`

---

# Planned Actions

- [x] Verify git status, develop clean
- [x] Create TASK-166 branch
- [ ] Scan current_community in target files
- [ ] Read target files before modification
- [ ] Remove current_community bindings/fallbacks
- [ ] Run targeted tests
- [ ] Verify scan after modification
- [ ] Update TASK → DONE + UNLOCKED
- [ ] check-task.sh
- [ ] Commit and push

---

# Progress Log

## 2026-05-29 19:17:29 Europe/Paris
Task created.

## 2026-05-29 19:20:00 Europe/Paris
TASK file updated with full scope, constraints, architecture rules.

## 2026-05-29 19:22:00 Europe/Paris
Scan completed:
- ResolveCommunity.php: `app()->instance('current_community', ...)` and `View::share('currentCommunity', ...)` (3 occurrences)
- ResolveUrlOrganization.php: `bindOrganization()` conditional `current_community` (3 occurrences)
- ResolveApiOrganization.php: `bindOrganization()` conditional `current_community` (3 occurrences)
- HasOrganizationId.php: `current_community` fallback in `creating` event (1 occurrence)
- CurrentOrganization.php: `current_community` fallback in `get()` (3 occurrences in docblock + code)
- ResolveOrganization.php: docblock comment reference (1 occurrence)

## 2026-05-29 19:23:00 Europe/Paris
All bindings removed from 5 source files:
1. `ResolveCommunity.php`: Removed `app()->instance('current_community', ...)` and both `View::share('currentCommunity', ...)` calls.
2. `ResolveUrlOrganization.php`: Removed conditional `current_community` binding from `bindOrganization()`.
3. `ResolveApiOrganization.php`: Removed conditional `current_community` binding from `bindOrganization()`.
4. `HasOrganizationId.php`: Removed `current_community` fallback from `creating` event.
5. `CurrentOrganization.php`: Removed `current_community` fallback from `get()` and updated docblock.

## 2026-05-29 19:30:00 Europe/Paris
Test adaptations completed (11 files modified):
- `ResolveUrlOrganizationTest.php`: Changed test to assert `current_organization` bound, `current_community` NOT bound.
- `ApiTenantScopingTest.php`: Changed test to assert `current_organization` bound, `current_community` NOT bound.
- `CurrentOrganizationTest.php`: Removed 3 fallback tests (get falls back, get fallbacks to community only, helper falls back).
- `OrganizationCompatibilityTest.php`: Updated 5 tests — removed `current_community` references from route handlers and assertions.
- `OrganizationRouteCompatibilityTest.php`: Updated 4 tests — replaced `app('current_community')->id` with `app('current_organization')->id`.
- `BelongsToTenantScopeTest.php`: Removed `test_scope_falls_back_to_current_community` test.
- `T1392LegacyCharacterizationTest.php`: Updated 3 failing tests. Added `test_current_organization_no_fallback_to_current_community` (asserts null).
- `T1403CurrentCommunityFallbackGatesTest.php`: Updated 2 gates — removed fallback test, updated ResolveCommunity bind test.
- `T1404OrganizationParallelRoutesTest.php`: Updated 2 tests — changed assertion and removed `forgetInstance('current_community')`.
- `T1405ARuntimeOrganizationIdTest.php`: Changed test to assert `current_community` NOT bound.
- `ResolveOrganization.php`: Updated docblock (comment only).

## 2026-05-29 19:40:00 Europe/Paris
All targeted tests passing (79 assertions across 6 test suites).
Post-modification scan: ZERO runtime `current_community` references remain in `app/`.

# Handoffs
None. Task self-contained.

# Tests

- [x] ResolveUrlOrganizationTest — 22/22 PASS (42 assertions)
- [x] ApiTenantScopingTest — 11/11 PASS (23 assertions)
- [x] CurrentOrganizationTest — 6/6 PASS (6 assertions)
- [x] OrganizationCompatibilityTest — 18/18 PASS (31 assertions)
- [x] OrganizationRouteCompatibilityTest — 9/9 PASS (16 assertions)
- [x] BelongsToTenantScopeTest — 13/13 PASS (16 assertions)
- [x] T1392LegacyCharacterizationTest — 28/28 PASS (46 assertions)
- [x] T1403CurrentCommunityFallbackGatesTest — 6/6 PASS (12 assertions)
- [x] T1404OrganizationParallelRoutesTest — 15/15 PASS (25 assertions)
- [x] T1405ARuntimeOrganizationIdTest — 14/14 PASS (19 assertions)
- [x] T1392KnownRisksTest — 2/2 PASS, 11 SKIPPED (no failures, some risks now resolved)

# Current Community Occurrences

## Pre-modification scan results

### app/Models/Traits/HasOrganizationId.php
- Line 13: `app()->bound('current_community') ? app('current_community') : null` → REMOVED

### app/Http/Middleware/ResolveCommunity.php
- Line 23: `app()->instance('current_community', $organization)` → REMOVED
- Line 25: `View::share('currentCommunity', $organization)` → REMOVED
- Line 28: `View::share('currentCommunity', null)` → REMOVED

### app/Http/Middleware/ResolveUrlOrganization.php
- Lines 288-290: Conditional `current_community` binding → REMOVED

### app/Http/Middleware/ResolveApiOrganization.php
- Lines 72-74: Conditional `current_community` binding → REMOVED

### app/Support/Tenancy/CurrentOrganization.php
- Lines 14-16, 29-31: Fallback and docblock → REMOVED

## Post-modification scan results
**ZERO** runtime `current_community` references remain in `app/`.

---

# Test Results
All targeted tests pass. Pre-existing PostgreSQL `RefreshDatabase` infrastructure issue (UniqueConstraintViolationException) remains when running multiple test classes without `--process-isolation`. Not related to these changes.

---

# Review Notes
- Known risks that are now RESOLVED by this task:
  1. "current_community should be removed" — RESOLVED (fallback removed from CurrentOrganization, bindings removed from middleware)
  2. "currentCommunity view variable should be removed" — RESOLVED (View::share removed from ResolveCommunity)
  3. "ResolveUrlOrganization should not bind current_community" — RESOLVED (bind removed)
  4. "ResolveCommunity should be deprecated" — PARTIALLY RESOLVED (no longer binds current_community, still exists as middleware for org-prefixed routes)
- `Community` model remains unchanged (BCE).
- `Organization extends Community` continues to work.
- `community_id` remains as DB column (legacy).
- `BelongsToTenantScope` now always uses `CurrentOrganization::get()` which only checks `current_organization`.

---

# Version Notes
**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
