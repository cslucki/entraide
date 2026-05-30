---
task_id: TASK-176
title: Cleanup remaining community references (views/variables/docblocks)

status: MERGED

owner: SUPERVISOR

contributors: []

branch: TASK-176-cleanup-remaining-community-references-views-variables-docblocks

priority: MEDIUM

created_at: 2026-05-30 22:12:51 Europe/Paris
updated_at: 2026-05-30 22:12:51 Europe/Paris

labels: []

lock:
  status: UNLOCKED

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Nettoyer les dernières références `community` dans le codebase après suppression de Community.php (TASK-173), des alias/middleware/routes (TASK-174), et du fix T0757 (TASK-175).

Rien de fonctionnel — uniquement du renommage de variables, vues, docblocks, et dossiers.

---

# Planned Actions

1. `app/Http/Middleware/ResolveOrganization.php:15` — `$request->route('community')` → supprimer le fallback legacy (plus de param `{community}` dans les routes)
2. `app/Http/Controllers/OrganizationRequestController.php:14` — vue `community-requests.create` → renommer si vue existe
3. `app/Http/Controllers/Admin/AdminOrganizationController.php:60` — vue `admin.communities.edit` → `admin.organizations.edit`, var `$community` → `$organization`
4. `app/Http/Controllers/Admin/AdminMetaOrganizationController.php:19` — vue `admin.meta-community.index` → `admin.meta-organization.index`
5. `app/Http/Controllers/OrganizationLandingController.php:32` — vue `community.landing` → `organization.landing`
6. `resources/views/admin/communities/` → renommer dossier en `resources/views/admin/organizations/`, adapter `edit.blade.php` (var `$community` → `$organization`)
7. `resources/views/admin/meta-community/` → renommer dossier si existe
8. `resources/views/community/` → renommer dossier en `resources/views/organization/`
9. `resources/views/community-requests/` → renommer dossier si existe
10. `app/Support/helpers.php:17-19` — mettre à jour docblock
11. `tests/Feature/OrganizationRelationshipsTest.php` — var `$community` → `$organization`
12. Vérifier qu'aucune vue cassée (chercher les références aux anciens chemins de vues)

---
# Progress Log


## 2026-05-30 22:12:51 Europe/Paris

Task created.

Owner:
SUPERVISOR

Branch:
TASK-176-cleanup-remaining-community-references-views-variables-docblocks

Status:
IN_PROGRESS

## 2026-05-30 22:18 Europe/Paris

SUPERVISOR edits (controllers, middleware, blade renames via git mv). Figé à "verify and test" (101K tokens).

ORCHESTRATOR reprend :
- Renommage dossiers vues restants : community-requests/ → organization-requests/, admin/meta-community/ → admin/meta-organization/
- Controller OrganizationRequestController : `community-requests.create` → `organization-requests.create`
- Unstage public/build/manifest.json (bruit unrelated)

Avant commit.

## 2026-05-30 22:27 Europe/Paris

VERIFICATOR **REJECTED** — 11 failures (middleware + view variable name).
- Middleware : revert `$request->route('community')` supprimé → restauré depuis develop
- Vue `admin/organizations/index.blade.php` : `$communities` → `$organizations` (le controller passe `$organizations`)
- Tests : 824 passes, 0 failures 🟢
- Amend commit en cours.

# Handoffs

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