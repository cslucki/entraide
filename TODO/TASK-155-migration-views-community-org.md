---
task_id: TASK-155
title: Migration Livewire + Blade community→organization

status: MERGED

owner: ORCHESTRATOR

contributors:
  - SUPERVISOR

branch: TASK-155-community-migration-views

priority: HIGH

created_at: 2026-05-28 19:04:30 Europe/Paris
updated_at: 2026-05-28 19:11:00 Europe/Paris

labels:
  - migration
  - views
  - blade
  - livewire
  - community→org

lock:
  status: UNLOCKED
  agent: ORCHESTRATOR
  since: 2026-05-28 19:11:00 Europe/Paris

handoff: false

pr:
  status: MERGED
  url: null
---

# TASK-155 — Migration Livewire + Blade community→organization

## Objective

Migrate Blade templates and Livewire components from Community to Organization terminology.

## Scope

### Blade templates modifiés (5)
- `resources/views/community/landing.blade.php` : `$community` → `$organization`
- `resources/views/admin/users.blade.php` : labels, model refs
- `resources/views/admin/users/edit.blade.php` : labels, variable names
- `resources/views/admin/meta-community/index.blade.php` : labels
- `resources/views/admin/communities/edit.blade.php` : title

### Config
- `config/terms.php` : comment update

### Livewire
- 0 références — déjà propre

---

# Planned Actions
- [x] Migrate `community/landing.blade.php`
- [x] Migrate `admin/users.blade.php`
- [x] Migrate `admin/users/edit.blade.php`
- [x] Migrate `admin/meta-community/index.blade.php`
- [x] Migrate `admin/communities/edit.blade.php`
- [x] Update `config/terms.php`
- [x] Merge into develop

---

# Progress Log

## 2026-05-28 19:04:30 Europe/Paris
Task created. Branch TASK-155 créée depuis develop après merge T154.

## 2026-05-28 19:10:51 Europe/Paris
Commit `518f38f` — 6 files modifiés, 37 insertions, 37 deletions. Tous les templates Blade migrés.

Livewire : 0 références community — déjà propre.

## 2026-05-28 19:11:00 Europe/Paris
Merge commit `861b457` — merge(t155): community→org views migration. Mergé dans develop.

---

# Handoffs

---

# Tests
- [x] Test suite stable (aucune régression)

---

# Test Results

| Test Run | Result |
|----------|--------|
| Full PHPUnit | stable |

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
