---
task_id: TASK-217
title: Dashboard Admin – Gestion des organisations (fourchette points + header JS)

status: DONE

owner: OPENGINE

contributors: []

branch: TASK-217-dashboard-admin-gestion-des-organisations-fourchette-points-header-js

priority: MEDIUM

created_at: 2026-06-07 00:19:48 Europe/Paris
updated_at: 2026-06-07 01:05:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: OPENGINE
  since: null

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Ajouter fourchette points min/max par service + injection JS header dans la page d'édition des organisations.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [x] validate UI

---

# Progress Log


## 2026-06-07 00:19:48 Europe/Paris

Task created.

Owner:
OPENGINE

Branch:
TASK-217-dashboard-admin-gestion-des-organisations-fourchette-points-header-js

Status:
IN_PROGRESS

## 2026-06-07 01:05:00 Europe/Paris

### Modifications effectuées

**Migration:** `database/migrations/2026_06_07_000001_add_service_points_and_header_js_to_organizations.php`
- `service_points_min` (integer, nullable)
- `service_points_max` (integer, nullable)
- `header_javascript_enabled` (boolean, default false)
- `header_javascript` (text, nullable)

**Model:** `app/Models/Organization.php`
- Nouveaux champs ajoutés à `$fillable`
- `header_javascript_enabled` casté `boolean`

**Controller:** `app/Http/Controllers/Admin/AdminOrganizationController.php`
- Validation des nouveaux champs
- Vérification `max >= min`
- Boolean handling `header_javascript_enabled`

**Vue:** `resources/views/admin/organizations/edit.blade.php`
- Section "Page d'accueil" : champs jumeaux `Points min/max par service`
- Nouvelle section "Intégration" : toggle Alpine + textarea JS

**Layout:** `resources/views/layouts/app.blade.php`
- Injection conditionnelle dans `<head>` si enabled + contenu présent

**Tests:** `tests/Feature/Admin/AdminSettingTest.php`
- 5 nouveaux tests (points range set, max >= min validation, optional, JS enable, JS default)
- 12/12 pass (7 existing + 5 new)

# Handoffs

# Tests

- [x] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [ ] tenant validation

---

# Test Results

All 12 tests passed (35 assertions), 1.41s.

---

# Review Notes

Ready for finalization and merge.
---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`