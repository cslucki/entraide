---
task_id: TASK-221
title: Fix admin organization primary loop select

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-221-fix-admin-organization-primary-loop-select

priority: MEDIUM

created_at: 2026-06-07 19:25:22 Europe/Paris
updated_at: 2026-06-07 19:43:15 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: 2026-06-07 19:25:22 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Fix the admin Organization edit page regression introduced by TASK-220 where the primary loop `<select>` crashes with `Undefined property: stdClass::$id` when an organization has loops.

Scope is limited to the admin Organization primary loop field and directly related update compatibility regressions.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [x] validate UI render path through feature test

---
# Progress Log


## 2026-06-07 19:25:22 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-221-fix-admin-organization-primary-loop-select

Status:
IN_PROGRESS

## 2026-06-07 19:28:30 Europe/Paris

Implemented fix.

Root cause:
- `resources/views/admin/organizations/edit.blade.php` used `@foreach ($loops as $loop)` inside Blade.
- Laravel injects a reserved `$loop` metadata variable inside `@foreach` blocks.
- The template then accessed Blade's loop metadata `stdClass` as if it were a `Loop` model, triggering `Undefined property: stdClass::$id`.

Changes:
- Renamed the Blade iteration variable from `$loop` to `$availableLoop` in the primary loop select.
- Added a regression test rendering admin Organization edit when the organization has loop options.
- Fixed a related TASK-220 update regression where `AdminOrganizationController@update` accessed missing `primary_loop_id` for existing update forms that do not submit that field.
- Scoped `primary_loop_id` validation to loops belonging to the edited Organization to prevent cross-Organization primary loop assignment.
- Ran Pint on modified PHP files.

Modified files:
- `app/Http/Controllers/Admin/AdminOrganizationController.php`
- `resources/views/admin/organizations/edit.blade.php`
- `tests/Feature/Admin/AdminCommunitiesTest.php`
- `tests/Feature/LoopOrganizationModeTest.php`
- `TODO/TASK-221-fix-admin-organization-primary-loop-select.md`

Status:
- Implementation complete.
- Tests pass.
- Not committed or pushed yet; awaiting explicit commit/finalize instruction.

## 2026-06-07 19:40:28 Europe/Paris

Added tenant-safety hardening for the new primary loop field:

- replaced broad `exists:loops,id` validation with an Organization-scoped `Rule::exists('loops', 'id')->where('organization_id', $organization->id)`;
- added regression test `test_admin_cannot_set_primary_loop_from_another_organization`;
- reran targeted safe PostgreSQL tests successfully.

## 2026-06-07 19:43:15 Europe/Paris

Marked task `DONE` before cleanup commit.

Branch cleanup policy:
- commit TASK-221 implementation and task log to make branch clean;
- do not merge before explicit GO from Cyril.

# Handoffs

## 2026-06-07 19:28:30 Europe/Paris

OPENCODE unlocked task after implementation and validation.

Pending:
- merge only after explicit GO from Cyril.

# Tests

- [x] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [x] tenant validation

---

# Test Results

Safe DB preflight completed before Laravel tests:

```bash
APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan config:show database.default
# database.default = pgsql

APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan config:show database.connections.pgsql.database
# database.connections.pgsql.database = bouclepro_test
```

Executed:

```bash
APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan test tests/Feature/Admin/AdminCommunitiesTest.php --compact
# PASS: 19 passed, 45 assertions

APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan test tests/Feature/LoopOrganizationModeTest.php --compact
# PASS: 10 passed, 26 assertions
```

---

# Review Notes

The fix keeps Organization scoping intact: the edit page still receives loops from `$organization->loops()->orderBy('name')->get()` and only changes the Blade item variable name.

The update endpoint now also validates `primary_loop_id` inside the edited Organization boundary.

Browser validation was not run; the rendering path is covered by the new HTTP feature regression test.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
