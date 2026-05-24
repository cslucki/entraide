---
task_id: TASK-140
title: T140.2 — Add organization_id to loops table

status: MERGED

owner: OPENCODE

contributors: []

branch: TASK-140-t140-2-add-organization-id-to-loops-table

priority: HIGH

created_at: 2026-05-24 19:34:56 Europe/Paris
updated_at: 2026-05-24 22:45:00 Europe/Paris

labels:
  - migration
  - organization
  - loops
  - non-destructive
  - no-runtime-service-changes

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: 2026-05-24 22:45:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Ajouter `organization_id` à la table `loops` (colonne nullable) + backfill depuis `community_id`
+ trait `HasOrganizationId` sur `Loop.php` + relation `organization()` BelongsTo.

Pré-condition obligatoire pour T140.1 (BelongsToTenantScope → organization_id).

**Ne touche PAS :** services, controllers, channels, routes, scopes, middleware.

---

# Planned Actions

- [x] Sous-agent A — DB & migration audit (read-only)
- [x] Sous-agent B — Loop runtime usage audit (read-only)
- [x] Sous-agent C — Tests & factory audit (read-only)
- [x] Sous-agent D — T139.2 gates review (read-only)
- [x] Synthèse → `_temp/T140.2-pre-flight.md`
- [x] Créer migration `2026_05_24_220000_add_organization_id_to_loops_table.php`
- [x] Modifier `app/Models/Loop.php` — HasOrganizationId + fillable + relation
- [x] Adapter `T1392LegacyCharacterizationTest` (2 tests)
- [x] Unskip `T1392KnownRisksTest` (test_known_risk_loop_should_have_organization_id)
- [x] Lancer migration + vérifier backfill (0 null)
- [x] Smoke gates (29 passes)
- [x] Tests caractérisation Loop (5 passes)
- [x] Known-risk Loop test (1 pass, unskipped)
- [x] Tests Loop complets (145 passes, 336 assertions)
- [x] Commit + finalize

---

# Progress Log

## 2026-05-24 19:34:56 Europe/Paris

Task created.

## 2026-05-24 22:30:00 Europe/Paris

4 sous-agents lancés en parallèle :

- A: DB & migration — FKs, indexes, pattern 2026_05_12_101622, SQLite compat
- B: Loop runtime — tous usages community_id, aucun impact du trait HasOrganizationId
- C: Factory & tests — LoopFactory OK sans modif, 2 tests characterization à adapter
- D: Gates — tous verts, 2 tests adaptation + 1 unskip known-risk

Synthèse dans _temp/T140.2-pre-flight.md. Aucun risque identifié.

## 2026-05-24 22:45:00 Europe/Paris

Migration créée et lancée (9ms). Backfill : 0 organization_id NULL.
Loop.php modifié : HasOrganizationId trait, organization_id fillable, organization() relation.
Factory inchangée (HasOrganizationId sync auto depuis community_id).

Tests adaptés :
- test_loop_does_not_have_organization_id_column → test_loop_has_organization_id (assertNotNull)
- test_loop_does_not_have_organization_relation → test_loop_has_organization_relation (assertTrue)
- test_known_risk_loop_should_have_organization_id : unskipped, passe (assertNotNull + assertEquals)

Résultats :
- Smoke gates T1392 : 29 passes (51 assertions)
- Caractérisation T1392 (loop) : 5 passes
- Known-risks (loop) : 1 pass
- Tests Loop complets : 145 passes, 336 assertions
- Backfill : 0 NULL

# Tests

- [x] Smoke gates (29 tests) — T1392RouteSmokeGatesTest
- [x] Caractérisation Loop (5 tests) — T1392LegacyCharacterizationTest
- [x] Known-risk Loop (1 test) — T1392KnownRisksTest
- [x] Admin loops (8 tests) — AdminLoopsTest
- [x] Admin messages (5 tests) — AdminMessagesTest
- [x] Loop activity tracking (10 tests) — LoopActivityTrackingTest
- [x] Loop creation (10 tests) — LoopCreationTest
- [x] Loop help request (19 tests) — LoopHelpRequestTest
- [x] Loop member invariant (22 tests) — LoopMemberInvariantTest
- [x] Loop message (21 tests) — LoopMessageTest
- [x] Loop model (19 tests) — LoopModelTest
- [x] Loop visibility membership (7 tests) — LoopVisibilityMembershipTest
- [x] Routes tenant safety (17 tests) — T07411RoutesTenantSafetyTest

---

# Test Results

## php artisan test --filter=Loop
Tests: 145 passed (336 assertions). 0 failures.
Duration: 6.44s

## Gates T139.2
- Smoke: 29/29 passes
- Characterization (loop): 5/5 passes (adapted)
- Known-risks (loop): 1/1 pass (unskipped)

## Backfill
php artisan tinker: Loop::whereNull('organization_id')->count() = 0

# Review Notes

## Fichiers modifiés
1. `database/migrations/2026_05_24_220000_add_organization_id_to_loops_table.php` (NEW)
2. `app/Models/Loop.php` (MODIFIED — trait, fillable, relation)
3. `tests/Feature/T1392LegacyCharacterizationTest.php` (MODIFIED — 2 tests adaptés)
4. `tests/Feature/T1392KnownRisksTest.php` (MODIFIED — unskip)
5. `TODO/TASK-140-t140-2-add-organization-id-to-loops-table.md` (MODIFIED)
6. `_temp/T140.2-pre-flight.md` (NEW — synthèse sous-agents)

## Périmètre strict respecté
- Aucun changement service/controller/scope/middleware/channel/route
- community_id reste NOT NULL et canonique
- Aucun renommage
- Aucune modification LoopFactory (HasOrganizationId sync auto)
- Migration compatible SQLite (down() no-op, pas de FK ADD CONSTRAINT)

## Recommandation T140.1 (BelongsToTenantScope)
**Go.** T140.2 termine. Loop a maintenant organization_id, indexé, backfillé,
HasOrganizationId actif, organization() relation prête.
T140.1 peut basculer BelongsToTenantScope vers organization_id sans casser Loop.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
