---
task_id: TASK-196
title: Create a default config scope for OrganizationSettings

status: DONE

owner: ORCHESTRATOR

contributors: []

branch: TASK-196-create-a-default-config-scope-for-organizationsettings

priority: MEDIUM

created_at: 2026-06-02 09:36:35 Europe/Paris
updated_at: 2026-06-02 09:49:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: ORCHESTRATOR
  since: 2026-06-02 09:49:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Create a default config scope for OrganizationSettings so callers can omit the organization ID and fall back to the default organization.

---

# Changes

### `app/Models/OrganizationSetting.php`
- Added `getDefaultOrgId(): ?string` — resolves default org from `is_default = true`
- Changed `get()` signature: `string $organizationId` → `Organization|string|null $organization = null`
  - When `null` or omitted, falls back to `getDefaultOrgId()`
  - When `Organization` instance, extracts `->id`
  - When string, used as-is (backward compatible)
- Changed `set()` signature: same pattern

### `app/Providers/AppServiceProvider.php`
- View composer: passes `$org` (Organization object) instead of `$org->id`
- Else/no-org branch: passes `null` to use `getDefaultOrgId()` instead of hardcoded config fallbacks

### `app/Http/Controllers/Admin/AdminSettingController.php`
- Added `// Admin org override` comments (keeps `auth()->user()->organization_id`)

### `app/Http/Controllers/Admin/AdminMetaOrganizationController.php`
- Replaced `auth()->user()->organization_id` with `OrganizationSetting::getDefaultOrgId()`
- Added `// Uses default config scope` comments

### `database/seeders/SettingSeeder.php`
- Moved `$org->update(['is_default' => true])` before setting calls
- Changed `OrganizationSetting::set($org->id, ...)` → `OrganizationSetting::set(null, ...)` (default config scope)

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [ ] validate UI

---

# Progress Log

## 2026-06-02 09:36:35 Europe/Paris

Task created.

## 2026-06-02 09:49:00 Europe/Paris

Implementation complete. All 825 tests passed, 11 skipped.

# Handoffs

N/A

# Tests

- [x] feature tests — 825 passed, 11 skipped

---

# Test Results

825 passed, 11 skipped.

---

# Review Notes

N/A

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`