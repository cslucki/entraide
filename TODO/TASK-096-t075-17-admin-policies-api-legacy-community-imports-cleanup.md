---
task_id: TASK-096
title: t075-17-admin-policies-api-legacy-community-imports-cleanup

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-096-t075-17-admin-policies-api-legacy-community-imports-cleanup

priority: MEDIUM

created_at: 2026-05-17 23:45:38 Europe/Paris
updated_at: 2026-05-18 00:02:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Réduire les imports legacy `use App\Models\Community;` dans les tests **Admin**, **Policies** et **API** quand le `Community` importé représente conceptuellement une **Organization**.

Il ne s'agit PAS de supprimer Community. Il s'agit de **nettoyer les tests** où Community est utilisé comme tenant par erreur de nommage, alors que la fixture ou le contexte est en réalité organisationnel.

---

# Architecture Context (T75)

- Organization = Tenant. Organisation scoping rule (T075.1).
- Loop ≠ Tenant. Partner ≠ Tenant. Community ≠ Tenant.
- `current_organization` = source runtime canonique.
- `organization_id` = canonique côté code.
- `community_id` = colonne DB legacy de transition uniquement.
- `Community`, `current_community`, `ResolveCommunity`, `BelongsToTenantScope` = legacy temporaire acceptable, mais à éviter dans les nouveaux tests.
- Ne pas introduire de nouveau vocabulaire Community.
- Ne pas casser la compatibilité runtime.

---

# Scope

## Inclus (strict)

- Tests dans `tests/Feature/Admin/` — nettoyage des imports `App\Models\Community` organisationnels
- Tests dans `tests/Feature/Policies/` — idem
- Tests dans `tests/Feature/Api/` — idem
- Fixtures / factories / Seeders test-only strictement nécessaires pour le cleanup (ex: `OrganizationFactory` si elle n'existe pas déjà)
- `TODO/TASK-096-t075-17-admin-policies-api-legacy-community-imports-cleanup.md` — mise à jour continue

## Exclus (strict — ne PAS toucher)

- Pas de migration DB
- Pas de suppression du modèle `App\Models\Community`
- Pas de remplacement global / search & replace
- Pas de changement runtime (app/, routes/, config/, resources/)
- Pas de changement contrôleur métier
- Pas de changement API métier
- Pas de changement Policy métier
- Pas de changement route
- Pas de changement UI / Livewire / Blade / Alpine
- Pas de ChatLoop
- Pas de nouvelle interface
- Pas de nouvelle feature métier
- Pas de modification PROD (main)
- Pas de changement database/, database/migrations/

---

# Rules

1. **Interdiction de giant search/replace** — chaque fichier modifié doit être inspecté individuellement.
2. **Interdiction de modification runtime** — ne pas toucher app/, routes/, resources/, config/ sauf si un helper test-only strict est nécessaire.
3. **Préférer Organization** dans les nouveaux imports/nommage de tests.
4. **Ne pas casser la compatibilité** — les tests doivent continuer à passer (PHPUnit + Playwright).
5. **Pas de nouveau vocabulaire Community** dans les nouveaux helpers, docs, tests.
6. **Documenter tout usage legacy restant** de Community dans ce TASK file.
7. **Migration incrementale** — un module à la fois, pas de refactor global.

---

# Planned Actions (OPS phase)

- [x] vérifier état Git (develop clean, up-to-date)
- [x] créer la tâche via create-task.sh
- [x] cadrer le TASK file (scope, règles, architecture)
- [x] handoff vers CODE pour audit + patch minimal

## Planned Actions (CODE phase — à faire par CODE)

- [x] inspect architecture des tests impactés
- [x] audit des imports `use App\Models\Community` dans tests/Feature/Admin/
- [x] audit des imports `use App\Models\Community` dans tests/Feature/Policies/
- [x] audit des imports `use App\Models\Community` dans tests/Feature/Api/
- [x] identifier les cas où Community = conceptuellement Organization
- [x] patch minimal : remplacer par Organization ou supprimer l'import si inutilisé
- [x] créer OrganizationFactory si nécessaire et non existante
- [x] vérifier les fixtures associées
- [x] run PHPUnit — tests Admin
- [x] run PHPUnit — tests Policies
- [x] run PHPUnit — tests API
- [x] run Playwright si applicable
- [x] valider aucun changement runtime
- [x] valider aucun changement PROD
- [x] update TASK status -> TESTING

---

# Progress Log


## 2026-05-17 23:45:38 Europe/Paris

Task created by OPS.

Owner:
OPENCODE

Branch:
TASK-096-t075-17-admin-policies-api-legacy-community-imports-cleanup

Status:
IN_PROGRESS

## 2026-05-17 23:45:38 Europe/Paris — OPS

Git state verified:
- Branch: develop
- Up to date with origin/develop
- Working tree clean
- Last commit: a8287dc Merge T075.16
- main / PROD not touched (b392a13)
- T075.16 = MERGED, branch conservée (origin/TASK-095-t075-16-...)

create-task.sh executed successfully:
- Task ID: TASK-096
- Task file: TODO/TASK-096-t075-17-admin-policies-api-legacy-community-imports-cleanup.md
- Branch: TASK-096-t075-17-admin-policies-api-legacy-community-imports-cleanup
- Status: IN_PROGRESS
- Lock: LOCKED by OPENCODE

OPS phase complete. Ready for handoff to CODE.

## 2026-05-17 23:55:59 Europe/Paris — CODE

Branch/state verified:
- Current branch: `TASK-096-t075-17-admin-policies-api-legacy-community-imports-cleanup`
- Initial worktree before CODE patch: only this TASK file was untracked
- Scope inspected: `tests/Feature/Admin/`, `tests/Feature/Policies/`, `tests/Feature/Api/`

Audit result:
- `tests/Feature/Api/ServiceApiTest.php`: `Community` fixture was conceptually an Organization (`$org`, bound as `current_organization`) — replaced with `Organization`.
- `tests/Feature/Api/TransactionApiTest.php`: `Community` fixture was conceptually an Organization (`$org`, bound as `current_organization`) — replaced with `Organization`.
- `tests/Feature/Admin/AdminUsersTest.php`: user assignment tests still exercise legacy route/field names (`assign-community`, `community_id`), but the tenant fixture is conceptually an Organization — replaced `Community` import/factory with `Organization`.
- `tests/Feature/Admin/AdminCategoriesTest.php`: category deletion protection uses a tenant `$org` bound as `current_organization` — replaced with `Organization`.
- `tests/Feature/Admin/AdminMessagesTest.php`: helper `makeOrg()` creates tenant fixtures for admin message scoping — replaced `Community` type/import/factory with `Organization`.
- `tests/Feature/Admin/AdminLoopsTest.php`: helper `makeOrg()` creates tenant fixtures for admin loop scoping — replaced `Community` type/import/factory with `Organization`.
- `tests/Feature/Admin/AdminCommunitiesTest.php`: left unchanged because it explicitly tests legacy `admin.communities` routes, `communities` table behavior, soft-delete/nullification, visibility flags, and current legacy admin community management UI contract.
- `tests/Feature/Policies/`: no `use App\Models\Community`, `Community::factory`, `Community::`, or `current_community` usages found. `community_id` usages remain as schema-bound legacy DB fixture columns.
- `tests/Feature/Api/ApiTenantScopingTest.php`: existing `current_community` assertion remains unchanged because it explicitly verifies that the organization-first API tenant flow does not bind legacy `current_community`.

Imports removed:
- `tests/Feature/Api/ServiceApiTest.php` — `use App\Models\Community;`
- `tests/Feature/Api/TransactionApiTest.php` — `use App\Models\Community;`
- `tests/Feature/Admin/AdminUsersTest.php` — `use App\Models\Community;`
- `tests/Feature/Admin/AdminCategoriesTest.php` — `use App\Models\Community;`
- `tests/Feature/Admin/AdminMessagesTest.php` — `use App\Models\Community;`
- `tests/Feature/Admin/AdminLoopsTest.php` — `use App\Models\Community;`

Remaining legacy usages and justification:
- `tests/Feature/Admin/AdminCommunitiesTest.php` keeps `use App\Models\Community;` and `Community::factory()` because the file explicitly covers legacy `admin.communities` routes and `communities` table behavior. Future T075.10 / final Community removal should migrate or replace this area when runtime routes/controllers are renamed.
- `community_id` remains in modified and unmodified tests only where current DB schema and legacy route/request fields still require it.
- No new `current_community` was introduced. Existing API assertion remains a compatibility guard.

Files modified so far:
- `tests/Feature/Api/ServiceApiTest.php`
- `tests/Feature/Api/TransactionApiTest.php`
- `tests/Feature/Admin/AdminUsersTest.php`
- `tests/Feature/Admin/AdminCategoriesTest.php`
- `tests/Feature/Admin/AdminMessagesTest.php`
- `tests/Feature/Admin/AdminLoopsTest.php`
- `TODO/TASK-096-t075-17-admin-policies-api-legacy-community-imports-cleanup.md`

Out-of-scope validation:
- No runtime app code modified.
- No DB, schema, migration, route, controller, middleware, business policy, or business API code modified.
- No UI, Livewire, Blade, Alpine, ChatLoop, or new business feature modified.
- No main / PROD branch touched.

Status moved to TESTING for targeted and full PHPUnit validation.

## 2026-05-17 23:57:40 Europe/Paris — CODE

Validation complete:
- `php artisan test tests/Feature/Admin` — PASS, 128 tests passed, 323 assertions.
- `php artisan test tests/Feature/Policies` — PASS, 57 tests passed, 66 assertions.
- `php artisan test tests/Feature/Api` — PASS, 37 tests passed, 100 assertions.
- `php artisan test` — PASS, 660 tests passed, 1422 assertions.

Playwright/browser validation:
- Not run; not applicable for this backend/test-only import cleanup. No UI, Blade, Livewire, Alpine, or frontend behavior changed.

# Handoffs

## Future handoff — T075.10 / Community legacy removal

- Revisit `tests/Feature/Admin/AdminCommunitiesTest.php` when legacy `admin.communities` runtime routes/controllers/UI are migrated to Organization terminology.
- Revisit remaining schema-bound `community_id` fixtures after DB-level Organization migration removes or aliases the legacy column.

# Tests

- [x] `php artisan test tests/Feature/Admin`
- [x] `php artisan test tests/Feature/Policies`
- [x] `php artisan test tests/Feature/Api`
- [x] `php artisan test`
- [x] feature tests
- [x] browser validation — not applicable, no frontend/UI changes
- [x] responsive validation — not applicable, no frontend/UI changes
- [x] console inspection — not applicable, no frontend/UI changes
- [x] tenant validation — covered by Admin/Policies/API/full PHPUnit suites

---

# Test Results

- PASS — `php artisan test tests/Feature/Admin`: 128 tests passed, 323 assertions.
- PASS — `php artisan test tests/Feature/Policies`: 57 tests passed, 66 assertions.
- PASS — `php artisan test tests/Feature/Api`: 37 tests passed, 100 assertions.
- PASS — `php artisan test`: 660 tests passed, 1422 assertions.

---

# Review Notes

- Audit complete. Patch is test-only and Organization-first where the tenant fixture was conceptual Organization.
- Legacy `AdminCommunitiesTest` intentionally remains Community-based pending future runtime/domain migration.

## 2026-05-18 00:02:00 Europe/Paris — OPS (Finalization)

### OPENAI Review Summary

Verdict: **APPROVE WITH NOTES**
Blocking issues: **none**
Recommendation: **READY FOR FINALIZE**

### OPENAI Review Notes

- Patch limité aux 6 fichiers de tests Admin/API ciblés + TASK file.
- Aucun runtime modifié.
- Remplacements Community → Organization corrects : les fixtures représentent bien le tenant Organization, souvent bindé comme `current_organization`.
- Les `community_id` restants sont des champs de fixture liés au schéma legacy, pas un nouveau concept Community.
- Aucun helper permissif, abstraction dangereuse, `current_community`, `ResolveCommunity` ou refactor global introduit.
- Restes legacy justifiés :
  - `AdminCommunitiesTest` teste explicitement les routes/table legacy `communities`.
  - `ApiTenantScopingTest` garde une assertion de non-binding legacy.
- Risque résiduel : noms de routes/champs admin community restent legacy, hors scope T075.17, handoff futur.

### OPENAI Tests Relancés

- `php artisan test tests/Feature/Admin` — 128 passed, 323 assertions
- `php artisan test tests/Feature/Policies` — 57 passed, 66 assertions
- `php artisan test tests/Feature/Api` — 37 passed, 100 assertions

### OPS Finalization

- Review OPENAI intégrée : APPROVED, aucun blocking issue.
- Aucun runtime / DB / route / controller / middleware / policy métier / API métier / UI modifié.
- Risque résiduel legacy admin community documenté comme handoff futur.
- Status: DONE.
- Lock: UNLOCKED.
