---
task_id: TASK-163
title: Night batch organization migration cleanup

status: DONE

owner: OPENCODE

contributors:
  - SUPERVISOR

branch: TASK-163-night-batch-organization-migration-cleanup

priority: MEDIUM

created_at: 2026-05-29 00:19:30 Europe/Paris
updated_at: 2026-05-29 02:00:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: 2026-05-29 01:00:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Fix 6 seeders that still write `community_id` (column dropped in T151) and/or use `\App\Models\Community` instead of `\App\Models\Organization`.

Also add a `main` organization as the default platform organization.

---

# Scope (Lot 1 — Seeders)

All changes in `database/seeders/*` only.

| Seeder | Problem | Fix |
|--------|---------|-----|
| CommunitySeeder | No `main` org, uses Community | Add `main` org, switch to Organization |
| UserSeeder | Writes `community_id`, uses Community | → `organization_id`, Organization |
| QaAccountsSeeder | Writes `community_id`, uses Community | → `organization_id`, Organization |
| LegacyDataOrganizationSeeder | Writes `community_id`, uses Community | → `organization_id`, Organization |
| SettingSeeder | Uses Community | → Organization, prefer `main` org |
| DemoSeeder | Writes `community_id`, uses Community, wrong BelongsToTenantScope import | → `organization_id`, Organization |

**Rules:**
- `Organization extends Community` — BC preserved
- All models have `fillable: ['organization_id']` — works
- `HasOrganizationId` trait auto-sets on create — explicit set still fine
- Do NOT touch files outside `database/seeders/`
- `CommunitySeeder.php` name stays (legacy alias is fine)

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] handoff to SUPERVISOR
- [x] SUPERVISOR implements fixes
- [x] run PHPUnit — 831/0/11 ✅
- [x] verify seeders run without error — `php artisan db:seed --force` ✅
- [x] Lot 2 — Factory + CommunityModelTest: switch to Organization model

---

# Lot 2 — Factory & Test

| File | Change |
|------|--------|
| `database/factories/CommunityFactory.php` | `$model = Community::class` → `Organization::class` |
| `tests/Feature/CommunityModelTest.php` | All 9 `Community::` → `Organization::` |

**Validation:** `php artisan test --filter=CommunityModelTest` → 10/10 ✅

---

# Lot 3 — Blade dead code

`resources/views/admin/users/edit.blade.php:62` — removed dead `$user->community_id` fallback (column dropped in T151).

**Validation:** `php artisan test --filter=Admin` → 200/0 ✅

---

# Progress Log

## 2026-05-29 00:19:30 Europe/Paris
Task created.

## 2026-05-29 00:50:00 Europe/Paris
Scope defined: Lot 1 — Seeders fix. Handing off to SUPERVISOR.

## 2026-05-29 01:00:00 Europe/Paris
SUPERVISOR completed all 6 seeder edits. Changes:
- CommunitySeeder: added `main` org, switched to Organization
- UserSeeder: community_id→organization_id, Community→Organization
- QaAccountsSeeder: same + rename qaCommunities→qaOrganizations
- LegacyDataOrganizationSeeder: same + resolveDefaultOrganization() prefers slug 'main'
- SettingSeeder: prefers Organization where slug = 'main'
- DemoSeeder: all community_id→organization_id, removed unused import

`php artisan db:seed --force` ✅ (all 9 seeders, 0 errors)
PHPUnit: 831/0/11 ✅

# Handoffs

## 2026-05-29 01:00:00 Europe/Paris
Handoff de SUPERVISOR vers ORCHESTRATOR. Lot 1 terminé. Prêt pour commit.

# Tests

- [x] seeders (feature)
- [x] factory (feature)
- [x] blade dead code (feature)
- [x] PHPUnit full suite 831/0/11

---

# Test Results

## Final suite — 2026-05-29 02:00
**831 passed, 0 failed, 11 skipped, 1764 assertions**
Pre-existing 11 skipped = `T1392KnownRisksTest` (known risk markers), unchanged.

---

# Review Notes

## Night batch summary (00:19 → 02:00)

### Lot 1 — Seeders (SUPERVISOR)
6 files fixed: CommunitySeeder, UserSeeder, QaAccountsSeeder, LegacyDataOrganizationSeeder, SettingSeeder, DemoSeeder
- `community_id` → `organization_id` in all create/update arrays
- `App\Models\Community` → `App\Models\Organization`
- `main` org added as default platform organization
- `resolveDefaultCommunity()` → `resolveDefaultOrganization()`, prefers `main` slug

### Lot 2 — Factory (SUPERVISOR)
- `CommunityFactory::$model` → `Organization::class`
- `CommunityModelTest` all 9 `Community::` → `Organization::`

### Lot 3 — Blade dead code
- Removed dead `$user->community_id` fallback in `admin/users/edit.blade.php`

### Remaining for next task
- **Routes:** `/{community}` → `/org/{organization}` — requires routes layer migration
- **Views/admin:** `admin/communities/` directory + `$community` variable naming — tied to routes
- **Blade fallbacks:** `$currentCommunity` in `dashboard`, `navigation`, `app` — intentional compat layer
- **Middleware:** `current_community` bindings — intentional compat layer
- **Tests:** `current_community` test references — will be cleaned when compat layer removed

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`