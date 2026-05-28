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
updated_at: 2026-05-29 00:19:30 Europe/Paris

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