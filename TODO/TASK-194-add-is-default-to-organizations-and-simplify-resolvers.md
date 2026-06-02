---
task_id: TASK-194
title: Add is_default to organizations and simplify resolvers

status: IN_PROGRESS

owner: ORCHESTRATOR

contributors:
  - SUPERVISOR

branch: TASK-194-add-is-default-to-organizations-and-simplify-resolvers

priority: MEDIUM

created_at: 2026-06-02 08:45:19 Europe/Paris
updated_at: 2026-06-02 08:51:19 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: ORCHESTRATOR
  since: 2026-06-02 08:51:19 Europe/Paris

handoff: false

pr:
  status: READY
  url: null
---

# Objective

Ajouter `is_default` (boolean, nullable) sur `organizations`. Une seule organisation peut être `is_default = true` (contrainte unique partielle PostgreSQL). Remplacer l'ancien mécanisme `Setting::get('default_organization_id')` par une requête directe `where('is_default', true)`.

Simplifier les 3 résolveurs qui avaient 3-4 niveaux de fallback.

Migrer les seeders : au lieu de `Setting::set('default_organization_id', $org->id)`, utiliser `$org->update(['is_default' => true])`.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [ ] ~~validate UI~~ — aucun changement UI (refactor backend uniquement)

---

# Progress Log


## 2026-06-02 08:45:19 Europe/Paris

Task created by SUPERVISOR. Implementation done.

## 2026-06-02 08:51:19 Europe/Paris

ORCHESTRATOR:
- Fixed T1392RouteSmokeGatesTest: restored RefreshDatabase import after SUPERVISOR over-removed it
- Fixed T1405ARuntimeOrganizationIdTest: restored LoopMember + RefreshDatabase + property declarations + tearDown (SUPERVISOR over-removed everything). Applied only the correct changes: remove Setting::set → replace with update(['is_default' => true])
- Fixed AdminUsersTest: gave test's default org `is_default => true` (failing resolver after migration)
- Removed test_default_organization_id_static_is_null_by_default (tests removed static property)
- Full suite: 825 passed (1 removed test legitimate), 11 skipped, 0 failures

# Handoffs

None.

# Tests

- [x] feature tests — 825/825 pass (baseline + 1 removed test = 825)
- [ ] ~~browser validation~~ — aucun changement frontend
- [ ] ~~responsive validation~~ — aucun changement frontend
- [ ] ~~console inspection~~ — aucun changement frontend
- [ ] tenant validation — scope unchanged (is_default résolu dans l'org active)

---

# Test Results

825 passed, 11 skipped, 0 failures — nouveau baseline (1 test retiré : testait `$defaultOrganizationId` qui n'existe plus).

All modified tests verified individually.

---

# Review Notes

## R5 fix (VERIFICATOR review): is_default UNIQUE

Le SUPERVISOR utilisait `$table->boolean('is_default')->unique()` dans la migration. Cela empêche les orgs non-défaut d'avoir `is_default = false` (unique sur false aussi). Fixé : pas de ->unique() sur la colonne, mais un UNIQUE INDEX conditionnel en PostgreSQL (`WHERE is_default = true`). SQLite n'a pas de partial unique index — on accepte la différence.

## SUPERVISOR over-zealous edits

Le SUPERVISOR a trop supprimé dans 2 fichiers test :
- `T1392RouteSmokeGatesTest.php` : retiré `RefreshDatabase` + `ResolveUrlOrganization` import
- `T1405ARuntimeOrganizationIdTest.php` : retiré `LoopMember`, `RefreshDatabase`, propriétés de classe, `use RefreshDatabase` trait, ajouté tearDown incorrect

Les deux ont été restaurés par ORCHESTRATOR avec seulement les changements corrects ré-appliqués.

## Tests retirés

- `test_default_organization_id_static_is_null_by_default` : testait `ResolveUrlOrganization::$defaultOrganizationId` qui n'existe plus. Supprimé.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
