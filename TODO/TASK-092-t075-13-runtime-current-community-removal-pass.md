---
task_id: TASK-092
title: 'T075.13 — Runtime current_community Removal Pass'

status: MERGED

owner: OPENCODE

contributors: []

branch: TASK-092-t075-13-runtime-current-community-removal-pass

priority: MEDIUM

created_at: 2026-05-17 20:54:41 Europe/Paris
updated_at: 2026-05-17 ~21:30 Europe/Paris

labels:
  - review-approved

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

Reduce runtime dependencies on current_community and make current_organization the normal canonical runtime reference, while preserving strictly necessary legacy compatibility.

---

# Planned Actions

## Audit & Classify
- [x] audit runtime current_community bindings and reads across the codebase
- [x] classify each occurrence:
  - removable now
  - replaceable by current_organization
  - temporary legacy fallback required
  - future DB migration handoff

## Implementation
- [x] replace only safe runtime usages of current_community with current_organization
- [x] preserve community_id DB legacy compatibility
- [x] update CurrentOrganization helper only if strictly necessary
- [x] modify existing middleware only if minimal modification is necessary
- [x] add or adjust targeted PHPUnit tests
- [x] document remaining current_community usages in this TASK file

## Validation
- [x] run targeted CurrentOrganization / tenant runtime tests
- [x] run relevant existing tenant tests
- [x] run full php artisan test
- [ ] CI PostgreSQL green before merge

---

# Strict Scope Included
- current_community runtime binding / reading
- CurrentOrganization helper only if strictly necessary
- Existing middleware only if minimal modification is necessary
- Targeted PHPUnit tests
- TASK file

# Strict Scope Excluded
- No DB migration
- No massive Community removal
- No global community_id replacement
- No broad business controller rewrite
- No broad API change
- No Policy change unless strictly required by a targeted test
- No route legacy global rewrite
- No UI
- No ChatLoop
- No new business feature
- No PROD modification

---
# Progress Log


## 2026-05-17 20:54:41 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-092-t075-13-runtime-current-community-removal-pass

Status:
IN_PROGRESS

## 2026-05-17 ~21:00 Europe/Paris

### Audit pass
- Full rg audit of `current_community` runtime occurrences completed.
- Separated into 5 categories (bindings, readings, tests, docs, blade views).
- Findings documented in Review Notes.

### Patch pass
- `LoopController.php`: replaced 2 direct `current_community` reads with `CurrentOrganization::get()` and `CurrentOrganization::id()`.
- Added `CurrentOrganization` import.
- `LoopMemberInvariantTest.php`: fixed 2 tests that only bound `current_community` without `current_organization` — now binds both to match real middleware behavior.

### Validation
- Full suite: 655 passed, 0 failed.
- All targeted tests green.
- Only 2 files modified, 15 insertions / 6 deletions.
- No files outside strict scope touched.

### Status
Ready for review.

## 2026-05-17 ~21:30 Europe/Paris

### Review
- OPENAI / Codex: **APPROVE WITH NOTES**
- Blocking issues: none
- All targeted tests re-executed by reviewer — all green.

### Finalization
- TASK status → DONE
- Lock → UNLOCKED
- Functional files modified: 2 (LoopController.php, LoopMemberInvariantTest.php)
- TASK file updated: review notes, handoff section, scope clarification added.
- Waiting for finalization and merge via official scripts.

# Handoffs

# Tests

- [x] feature tests (655 passed, 0 failed)
- [ ] browser validation (no UI changes, not applicable)
- [ ] responsive validation (no UI changes, not applicable)
- [ ] console inspection (no UI changes, not applicable)
- [x] tenant validation (22 tenant tests in LoopMemberInvariantTest pass)

---

# Test Results

## 2026-05-17

### Baseline (before patch):
- CurrentOrganizationTest: 11 passed
- OrganizationCompatibilityTest: 18 passed
- BelongsToTenantScopeTest: 14 passed
- OrganizationRouteCompatibilityTest: 9 passed
- LoopMemberInvariantTest: 22 passed
- ResolveUrlOrganizationTest: 22 passed
- ApiTenantScopingTest: 11 passed
- Total: all passing

### After patch:
- Full suite: **655 passed, 0 failed** (1410 assertions, 28.81s)

### Targeted validation:
- php artisan test --filter=CurrentOrganizationTest → 11 passed
- php artisan test --filter=LoopMemberInvariantTest → 22 passed (was 2 failed after initial patch, fixed by adding `current_organization` binding in test setup)
- php artisan test --filter=OrganizationCompatibilityTest → 18 passed
- php artisan test --filter=BelongsToTenantScopeTest → 14 passed
- php artisan test --filter=OrganizationRouteCompatibilityTest → 9 passed
- php artisan test --filter=T075 (matched OrganizationCompatibilityTest) → 18 passed
- php artisan test --filter=ResolveUrlOrganizationTest → 22 passed
- php artisan test --filter=ApiTenantScopingTest → 11 passed

---

# Review Notes

## Audit Summary (2026-05-17)

### Category A: Runtime Bindings (container writes) — 2 files
| File | Line | Code | Verdict |
|------|------|------|---------|
| `app/Http/Middleware/ResolveCommunity.php` | 22 | `app()->instance('current_community', $community)` | **KEEP** — legacy middleware, binds both keys |
| `app/Http/Middleware/ResolveUrlOrganization.php` | 250-251 | `app()->instance('current_community', $organization)` | **KEEP** — compatibility fallback in new middleware |

### Category B: Runtime Readings (container reads) — 2 files with direct reads
| File | Line | Code | Verdict |
|------|------|------|---------|
| `app/Support/Tenancy/CurrentOrganization.php` | 29-30 | `bound('current_community') → app('current_community')` | **KEEP** — central documented legacy fallback |
| `app/Http/Controllers/LoopController.php` | 26-27, 83-84 | `bound('current_community') → app('current_community')` | **PATCHED** → replaced with `CurrentOrganization::get()` / `CurrentOrganization::id()` |

### Category C: Tests — 7 files
All test files kept as-is (they document the compatibility layer behavior):
- `CurrentOrganizationTest.php` — tests the fallback
- `BelongsToTenantScopeTest.php` — tests legacy fallback path
- `OrganizationCompatibilityTest.php` — tests middleware compatibility
- `OrganizationRouteCompatibilityTest.php` — tests route compatibility
- `ResolveUrlOrganizationTest.php` — tests legacy current_community binding
- `ApiTenantScopingTest.php` — verifies API doesn't bind legacy
- `LoopMemberInvariantTest.php` — tests cross-community isolation (2 tests adapted)

### Category D: Docs / Comments / TASK files — not modified (out of scope for this pass)

### Category E: Blade views — not modified (out of scope for this pass)

---

## Changes Made

### 1. `app/Http/Controllers/LoopController.php`
- Added `use App\Support\Tenancy\CurrentOrganization` import
- `resolveCommunity()`: replaced `app('current_community')` with `CurrentOrganization::get()`
- `resolveCommunityId()`: replaced `app('current_community')->id` with `CurrentOrganization::id()`
- Both now use the canonical Organization-first resolution chain:
  `current_organization → current_community (legacy fallback) → null`

### 2. `tests/Feature/LoopMemberInvariantTest.php`
- `test_cross_community_route_prefix_is_blocked()`: added `app()->instance('current_organization', ...)` alongside `current_community` to match real ResolveCommunity middleware behavior
- `test_cross_community_creation_is_blocked()`: same fix

### Remaining Legacy Fallbacks (documented)
These locations still reference `current_community` as runtime — all are legitimate:
1. **`CurrentOrganization::get()`** — central helper, `current_community` fallback kept as documented
2. **`ResolveCommunity` middleware** — binds both `current_community` and `current_organization`
3. **`ResolveUrlOrganization::bindOrganization()`** — sets `current_community` as compatibility fallback
4. **Tests** — all tests that verify legacy fallback behavior
5. **Blade views** — `$currentCommunity ?? $currentOrganization` pattern (UI, not in this pass scope)
6. **Doc files** — AGENTS.md, CLAUDE.md, TODO/*.md, ai/context/*.md

All remaining fallbacks are intentional and documented for migration compatibility.

### Files Modified (exactly 2)
```
app/Http/Controllers/LoopController.php   | 13 +++++++++----
tests/Feature/LoopMemberInvariantTest.php |  8 ++++++--
2 files changed, 15 insertions(+), 6 deletions(-)
```

### Scope Compliance
- ✅ No DB migration
- ✅ No global community_id replacement
- ✅ No giant search/replace
- ✅ No mass Community renaming
- ✅ No new ResolveCommunity
- ✅ No new Community services/helpers
- ✅ No UI changes
- ✅ No broad API changes
- ✅ No Policy changes
- ✅ No legacy route rewrites
- ✅ No PROD modifications
- ✅ No files outside scope

---

# Review — OPENAI / Codex

## Verdict

**APPROVE WITH NOTES**

Blocking issues: none

## Notes

- `LoopController` correctly replaces direct `app('current_community')` reads with `CurrentOrganization::get()` and `CurrentOrganization::id()`.
- `CurrentOrganization` is the correct runtime entry point: `current_organization` priority, centralized legacy fallback.
- No new direct dependency on `current_community` is introduced.
- Adaptations in `LoopMemberInvariantTest` are consistent with real middleware behavior.
- Legacy compatibility preserved.
- Scope respected: no migration, no route, no API, no Policy, no UI, no global refactor.
- Clarification: **Functional files modified: 2** (`LoopController.php`, `LoopMemberInvariantTest.php`). The TASK file was also updated for audit/review/finalization tracking — this is a 3rd file but is operational documentation, not functional code.

## Tests re-executed by reviewer

- `php artisan test --filter=LoopMemberInvariantTest`: 22 passed
- `php artisan test --filter=CurrentOrganizationTest`: 11 passed
- `php artisan test --filter=BelongsToTenantScopeTest`: 14 passed
- `php artisan test --filter=OrganizationCompatibilityTest`: 18 passed
- `php artisan test --filter=ResolveUrlOrganizationTest`: 22 passed
- `php artisan test --filter=OrganizationRouteCompatibilityTest`: 9 passed

---

# Future Handoff

## Next pass: reduce remaining legacy fallbacks

Locations still binding/reading `current_community` as runtime:

1. **`CurrentOrganization::get()`** — central helper, `current_community` fallback — final removal when DB migration drops `community_id`.
2. **`ResolveCommunity` middleware** — binds both `current_community` and `current_organization` — to simplify after middleware consolidation.
3. **`ResolveUrlOrganization::bindOrganization()`** — sets `current_community` as compatibility fallback — same.
4. **Tests** — test files that verify legacy fallback behavior — to simplify after removal pass.
5. **Blade views** — `$currentCommunity ?? $currentOrganization` pattern — UI pass.
6. **Doc files** — AGENTS.md, CLAUDE.md, TODO/*.md, ai/context/*.md — doc pass.

## Conditions for a future removal pass

- DB schema drops `community_id` column.
- All middleware consolidates to `current_organization` only.
- No runtime path reads `app('current_community')`.
- Blade views migrated.
- Tests updated.
- Docs updated.