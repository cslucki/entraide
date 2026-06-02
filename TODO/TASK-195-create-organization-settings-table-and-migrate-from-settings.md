---
task_id: TASK-195
title: Create organization_settings table and migrate from settings

status: MERGED

owner: ORCHESTRATOR

contributors:
  - SUPERVISOR

branch: TASK-195-create-organization-settings-table-and-migrate-from-settings

priority: MEDIUM

created_at: 2026-06-02 09:21:42 Europe/Paris
updated_at: 2026-06-02 09:21:42 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: ORCHESTRATOR
  since: 2026-06-02 09:35:00 Europe/Paris

handoff: false

pr:
  status: READY
  url: https://github.com/cslucki/entraide/pull/40
---

# Objective

Create `organization_settings` table and model, migrate data from `settings`, update controllers and views to use OrganizationSetting with org context.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [x] validate UI

---
# Progress Log


## 2026-06-02 09:21:42 Europe/Paris

Task created.

Owner:
SUPERVISOR

Branch:
TASK-195-create-organization-settings-table-and-migrate-from-settings

Status:
IN_PROGRESS

## 2026-06-02 09:33:00 Europe/Paris

Implementation complete.

**Modified files:**

| File | Action |
|------|--------|
| `database/migrations/2026_06_02_000001_create_organization_settings_table.php` | CREATE |
| `database/migrations/2026_06_02_000002_migrate_settings_to_organization_settings.php` | CREATE |
| `app/Models/OrganizationSetting.php` | CREATE |
| `app/Providers/AppServiceProvider.php` | MODIFY (view composer: Setting → OrganizationSetting) |
| `app/Http/Controllers/Admin/AdminSettingController.php` | MODIFY |
| `app/Http/Controllers/Admin/AdminMetaOrganizationController.php` | MODIFY |
| `database/seeders/SettingSeeder.php` | MODIFY |
| `tests/Feature/Admin/AdminSettingTest.php` | MODIFY |

**Migration fix:** Changed primary key from `['organization_id', 'key']` composite PK to auto-increment `id` + unique constraint. Eloquent `updateOrCreate` does not support composite PKs without `id` column (PostgreSQL `returning "id"` fails).

**Tests:** 825 passed, 11 skipped (same as baseline).

**Not modified (already clean from LOT1a):**
- `ApiTenantScopingTest.php` — no Setting references remain
- `T1405ARuntimeOrganizationIdTest.php` — no Setting references remain

# Handoffs

# Tests

- [x] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [ ] tenant validation

---

# Test Results

825 passed, 11 skipped (1755 assertions). All green.

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