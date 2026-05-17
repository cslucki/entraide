---
task_id: TASK-094
title: t075-15-fillable-tenantless-validation-guard

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-094-t075-15-fillable-tenantless-validation-guard

priority: MEDIUM

created_at: 2026-05-17 22:11:04 Europe/Paris
updated_at: 2026-05-17 22:11:04 Europe/Paris

labels: []

lock:
  status: UNLOCKED

handoff: true

pr:
  status: NOT_READY
  url: null
---

# Objective

Empêcher les modèles Organization-scopés de devenir tenantless via fillable, validation absente, mass assignment ou flux applicatif mal cadré.

---

# Périmètre

## Inclus

- audit des modèles tenant-scopés
- audit `$fillable` / `$guarded` pour `community_id` / `organization_id`
- audit validations store/update (FormRequest / direct)
- audit factories et helpers de tests
- tests PHPUnit ciblés si nécessaire
- guards minimaux si faille directe
- documentation des risques restants dans ce TASK file

## Exclu

- pas de migration DB
- pas de suppression globale `community_id`
- pas de remplacement global
- pas de refactor Community global
- pas de changement UI
- pas de ChatLoop
- pas de nouvelle interface
- pas de nouvelle feature métier
- pas de changement route/API/Policy sauf nécessité directe liée au guard tenantless
- pas de modification PROD

---

# Règles Architecture

- **Organization = Tenant.**
- Loop ≠ Tenant.
- Partner ≠ Tenant.
- Root domain n'est pas tenantless.
- Public ≠ global.
- `current_organization` est la source runtime canonique.
- `organization_id` est canonique côté code.
- `community_id` reste uniquement une colonne DB legacy de transition.
- Ne pas introduire de nouveau `current_community`.
- Ne pas créer de nouveau `ResolveCommunity`.
- Ne pas nommer de nouveaux tests/helpers/services avec Community comme concept actif.
- Ne pas faire de giant search/replace.

---

# Surfaces Probables

- Service
- ServiceRequest
- Transaction
- BlogPost
- Loop
- Referral (si concerné)
- Message / Review (si relation tenant indirecte concernée)
- factories et tests associés

---

# Planned Actions

- [ ] audit models tenant-scopés (BelongsToTenantScope, OrganizationScope)
- [ ] audit $fillable / $guarded pour community_id et organization_id
- [ ] audit FormRequest validations store/update
- [ ] audit factories et helpers de tests
- [ ] implémenter guards minimaux si faille directe
- [ ] écrire tests PHPUnit ciblés
- [ ] documenter risques restants
- [ ] lint / typecheck
- [ ] PHPUnit

---

# Progress Log

## 2026-05-17 22:11:04 Europe/Paris

Task created.

Owner: OPENCODE
Branch: TASK-094-t075-15-fillable-tenantless-validation-guard
Status: IN_PROGRESS

## 2026-05-17 23:00:00 Europe/Paris

AUDIT ONLY COMPLETE — NO PATCHES APPLIED.

---

# AUDIT REPORT T075.15

## A. Tableau modèles tenant-scopés

| Modèle | community_id dans $fillable | organization_id dans $fillable | HasOrganizationId | BelongsToTenantScope | risque tenantless create | risque tenantless update |
|--------|----------------------------|-------------------------------|------------------|----------------------|--------------------------|--------------------------|
| Service | oui (line 26) | oui (line 27) | oui (line 18) | oui (line 22) | **NON** — scope global + trait sync | **NON** — scope global + trait sync |
| ServiceRequest | oui (line 25) | oui (line 26) | oui (line 15) | oui (line 21) | **NON** — scope global + trait sync | **NON** — scope global + trait sync |
| Transaction | oui (line 23) | oui (line 24) | oui (line 15) | oui (line 19) | **NON** — scope global + trait sync | **NON** — scope global + trait sync |
| BlogPost | oui (line 23) | oui (line 24) | oui (line 19) | **NON** | **OUI** — pas de scope global | **OUI** — pas de scope global |
| Loop | oui (line 18) | **NON** | **NON** | **NON** | **OUI** — pas de scope + pas de trait | **OUI** — pas de scope + pas de trait |
| Referral | oui (line 23) | oui (line 24) | oui (line 15) | oui (line 19) | **NON** — scope global + trait sync | **NON** — scope global + trait sync |
| ReferralReward | oui (line 22) | oui (line 23) | oui (line 14) | oui (line 18) | **NON** — scope global + trait sync | **NON** — scope global + trait sync |

---

## B. Flux store/update à risque

### 1. ServiceController::store (app/Http/Controllers/ServiceController.php:64-131)
- **Risque exact**: Aucun — validation ne permet pas community_id/organization_id dans request (lines 71-82). Controller force community_id depuis currentOrganization() (line 86). Tamper-proof.
- **Niveau**: ✅ SAFE

### 2. TransactionController::store (app/Http/Controllers/TransactionController.php:19-86)
- **Risque exact**: 
  - Utilise `withoutGlobalScope(BelongsToTenantScope::class)` pour fetch Service/ServiceRequest (lines 32, 37)
  - Copie manuellement community_id depuis service/request vers transaction (lines 35, 41, 73)
  - Validation n'applique PAS de contrainte tenant (lines 21-25)
  - Si Service/ServiceRequest sont tenantless (bug), transaction devient tenantless
- **Niveau**: ⚠️ NON-BLOCKING — dépend de l'intégrité de Service/ServiceRequest
- **Observation**: Bypass justifié par le cas d'usage cross-organization (buyer dans org A, service dans org A). Mais pas de garde organisation_id.

### 3. LoopService::createLoop (app/Services/LoopService.php:14-37)
- **Risque exact**:
  - Utilise `user->community_id` (line 16) sans vérifier CurrentOrganization
  - Si user->community_id est null → RuntimeException (line 19)
  - Si user->community_id != current_organization → pas de validation
  - Loop n'a PAS organization_id
  - Pas de BelongsToTenantScope sur Loop
- **Niveau**: ⚠️ LEGACY TOLERATED — Loop ≠ Tenant par architecture
- **Observation**: Loop est modèle interne collaboratif, pas modèle métier tenant-scopé. Controller fait vérif manuelle (LoopController.php:135-137).

### 4. LoopController::store (app/Http/Controllers/LoopController.php:110-128)
- **Risque exact**: Aucun — délègue à LoopService avec resolveCommunity() qui vérifie membership (line 113).
- **Niveau**: ✅ SAFE

---

## C. Factories à risque

| Factory | Comportement actuel | Risque |
|---------|---------------------|--------|
| ServiceFactory | Définition sans community_id/organization_id (lines 17-28) | ⚠️ Tenantless test data si pas de HasOrganizationId |
| ServiceRequestFactory | Définition sans community_id/organization_id (lines 17-29) | ⚠️ Tenantless test data si pas de HasOrganizationId |
| TransactionFactory | Définition sans community_id/organization_id (lines 17-34) | ⚠️ Tenantless test data si pas de HasOrganizationId |
| LoopFactory | Définition avec Community::factory() (line 23) | ⚠️ Tenantless test data si Community factory défaut |
| ReferralFactory | Définition sans community_id/organization_id (lines 17-25) | ⚠️ Tenantless test data |
| ReferralRewardFactory | Définition sans community_id/organization_id (lines 18-27) | ⚠️ Tenantless test data |

**Observation critique**: Les models avec HasOrganizationId syncent automatiquement, MAIS si les factories ne set pas organization_id/community_id, HasOrganizationId ne peut rien sync → tenantless.

**Hors scope T075.15** : modifier les factories ou ajouter des state helpers (notamment toute variante `forOrganization(Community $org)`) est **explicitement exclu** de cette tâche. Le concept canonique est `Organization` ; nommer un nouveau helper avec `Community` comme type actif serait contraire aux règles d'architecture. À cadrer dans une tâche future dédiée si jugée nécessaire.

---

## D. Tests existants utiles

| Test | Couverture |
|------|------------|
| T0755ServicesRequestsTenantSafetyTest | Service/ServiceRequest hidden field tampering + cross-org access |
| T0756BlogOrganizationScopingTest | BlogPost full containment (index/show/store/update/destroy/comments) |
| HasOrganizationIdTest | Trait sync logic (create/update/null safety) |
| ApiTenantScopingTest | API tenant scoping |

---

## E. Tests appliqués dans T075.15

### T07515TransactionTenantSafetyTest (ajouté)
- **Scénario** : Guard `TransactionController::store` web.
- **Cas couverts** :
  - service cross-Organization → 404 ;
  - service tenantless (`community_id` null) → 404 ;
  - service même Organization que résolue → 201 + transaction créée ;
  - request cross-Organization → 404 ;
  - request tenantless (`community_id` null) → 404.

### Tests **hors scope** T075.15

Les scénarios suivants ont été identifiés pendant l'audit mais ne
seront **pas** ajoutés dans cette tâche. Tâche future éventuelle :

- BlogPost create sans scope global (lié au point F.1 hors scope) ;
- Factory tenantless behavior (lié au point F.2 hors scope) ;
- Loop cross-Community (Loop ≠ Tenant par architecture, hors scope).

---

## F. Patch minimal appliqué dans T075.15

**Seul patch effectivement appliqué dans T075.15** :

### TransactionController::store — Guard tenantless / cross-Organization
**Fichier**: app/Http/Controllers/TransactionController.php
**Détails** : voir section "PATCH MINIMAL T075.15" plus bas.

---

### Éléments explicitement **hors scope** T075.15

Les éléments suivants ont été identifiés pendant l'audit mais ne sont
**pas** traités dans cette tâche. Ils ne doivent **pas** être appliqués
sous le couvert de T075.15 :

1. **BlogPost — ajout de `BelongsToTenantScope`** : hors scope.
   Justification : les contrôleurs BlogPost actuels sont déjà safe
   (couvert par T0756). Ajouter un scope global sur BlogPost demande
   une revue dédiée (impact sur admin views, seeders, jobs, exports
   éventuels) qui dépasse le périmètre du guard `TransactionController`.
   Tâche future éventuelle si jugée nécessaire.

2. **Factories — ajout de helpers `forOrganization(...)`** : hors scope.
   Justification double :
   - modification de factories explicitement exclue du périmètre
     T075.15 ;
   - tout helper avec `Community` comme type actif (`forOrganization(Community $org)`)
     est contraire à la règle d'architecture : `Organization` est le
     concept canonique, `Community` est legacy DB uniquement.
   Tâche future éventuelle si jugée nécessaire, à concevoir avec
   `Organization` comme type.

3. **TransactionController — ajout explicite de `organization_id`
   dans `Transaction::create`** : hors scope.
   Justification : le trait `HasOrganizationId` backfille déjà
   `organization_id` depuis `community_id`. Ajouter une seconde
   source d'écriture mélangerait deux paradigmes (canonique vs
   legacy) sans bénéfice fonctionnel. Le guard appliqué dans
   T075.15 garantit déjà que `community_id` est non-null et égal
   à `currentOrganization()->id` avant la création.

---

## G. Handoff

### Ce que T075.15 corrige

Uniquement le guard `TransactionController::store` côté web :
- rejet tenantless (`community_id` null) sur Service et ServiceRequest ;
- rejet cross-Organization sur Service et ServiceRequest ;
- résolution de l'Organization courante via `currentOrganization()`,
  `abort(404)` si absente.

### Risques restants — documentés, **hors scope T075.15**

1. **BlogPost sans `BelongsToTenantScope`** — `BlogPost::create()` direct
   peut produire un record tenantless. Les contrôleurs BlogPost actuels
   sont safe (couverts par T0756) donc le risque n'est pas exploitable
   via le flux web standard.
   - **Hors scope T075.15.** Ne pas ajouter le scope dans cette tâche.
   - Tâche future éventuelle si jugée nécessaire après revue dédiée.

2. **Factories tenantless par défaut** — `ServiceFactory`,
   `ServiceRequestFactory`, `TransactionFactory`, `LoopFactory`
   produisent des records `community_id=null` sans state explicite.
   - **Hors scope T075.15.** Ne pas modifier les factories.
   - Ne pas ajouter de helper `forOrganization(Community $org)` — le
     type canonique est `Organization`, pas `Community`. Toute
     évolution future doit s'aligner sur la canonicalité.

3. **TransactionController — bypass `withoutGlobalScope` intentionnel**
   conservé pour le fetch Service/ServiceRequest. Le guard appliqué
   par T075.15 vérifie immédiatement après le fetch que la cible
   n'est ni tenantless ni cross-Organization. Pas de tâche future
   prévue.

4. **Loop sans `organization_id`** — Loop ≠ Tenant par architecture.
   Hors scope toute migration Organization. Pas de tâche future
   prévue dans la roadmap actuelle.

5. **`community_id` reste colonne DB legacy temporaire** — partout où
   `community_id` apparaît (scope, models, factories, transaction
   create), il s'agit de compatibilité DB. La canonicalité côté code
   reste `organization_id` ; le trait `HasOrganizationId` assure le
   sync. Aucune migration globale dans T075.15.

### Rappel des règles préservées

- `Organization = Tenant` ; `Loop ≠ Tenant` ; `Partner ≠ Tenant`.
- `current_organization` reste la source runtime canonique
  (`current_community` toléré en fallback legacy uniquement, non
  introduit ici).
- Pas de `ResolveCommunity` nouvellement créé.
- Pas de nouveau nommage Community comme concept actif.

---

# Modified Files

Aucun fichier modifié (audit only) — voir section "PATCH MINIMAL T075.15" plus bas pour les fichiers réellement modifiés.

---

# Git Status

```
?? TODO/TASK-094-t075-15-fillable-tenantless-validation-guard.md
```

Clean repo — uniquement TASK file non-tracké.

## 2026-05-17 22:11:04 Europe/Paris

Interruption CODE — recovery OPS.

- CODE a produit des patches spéculatifs sur BlogPost (BelongsToTenantScope)
  et 3 factories (Service, ServiceRequest, Transaction — null defaults + forOrganization).
- Patches non retenus : restaurés via `git restore`.
- Aucun commit effectué.
- Reprise demandée en audit-only.
- TASK file préservé, lock OPENCODE maintenu.

Modified files (restored):
- `app/Models/BlogPost.php`
- `database/factories/ServiceFactory.php`
- `database/factories/ServiceRequestFactory.php`
- `database/factories/TransactionFactory.php`

## 2026-05-17 22:30:00 Europe/Paris

AUDIT COMPLETED.

## 2026-05-17 23:00:00 Europe/Paris

FINALISATION OPS.

- OPENAI final review: APPROVED
- Tests ciblés: 31 passed (57 assertions)
- Full suite: 660 passed (1422 assertions)
- Pint: passed
- TASK status set to DONE
- Lock UNLOCKED
- Handoff activé (factories tenantless, BlogPost scope, Loop architecture)
- Aucun commit, aucun push, aucun merge — prêt pour finalize-task.sh

### Models Audited

**Models with BelongsToTenantScope + HasOrganizationId (SAFE):**
- Service ✅
- ServiceRequest ✅
- Transaction ✅
- Referral ✅
- ReferralReward ✅

All use HasOrganizationId trait which syncs community_id ↔ organization_id.

**Models VULNERABLE (missing tenant scope):**
- BlogPost ❌ — Has HasOrganizationId but NO BelongsToTenantScope
- Loop ❌ — NO HasOrganizationId, NO BelongsToTenantScope, only community_id

### Fillable Audit

All tenant-scoped models have BOTH `community_id` and `organization_id` in $fillable:
- Service: lines 25-35
- ServiceRequest: lines 24-36
- Transaction: lines 22-35
- BlogPost: lines 21-36
- Referral: lines 22-31
- ReferralReward: lines 21-31
- Loop: lines 17-25 (only community_id, NO organization_id)

### Factory Audit (CRITICAL ISSUE)

Factories creating tenantless records by default:
- ServiceFactory (lines 17-28): NO organization_id/community_id set
- ServiceRequestFactory (lines 17-29): NO organization_id/community_id set
- TransactionFactory (lines 17-34): NO organization_id/community_id set
- LoopFactory (lines 18-31): Uses Community::factory() but organization_id missing

### Controller Validation Audit

**ServiceController::store** (lines 64-131):
- ✅ Uses currentOrganization() to get resolved org
- ✅ Explicitly sets community_id from organization->id (line 86)
- ✅ Tamper-proof — ignores any community_id in request

**TransactionController::store** (lines 19-86):
- ⚠️ Uses `withoutGlobalScope(BelongsToTenantScope::class)` to fetch service/request (lines 32, 37)
- ⚠️ Manually copies community_id from service/request (lines 35, 41)
- ⚠️ Bypasses tenant scope protection during creation
- ⚠️ Validation does not enforce organization_id (line 68-76)

**LoopController::store + LoopService::createLoop**:
- ⚠️ Uses user->community_id (LoopService line 16)
- ⚠️ No organization_id handling
- ⚠️ Relies on manual controller checks (LoopController lines 134-137)

### HasOrganizationId Trait Analysis

Trait behavior (app/Models/Traits/HasOrganizationId.php):
- ✅ On creating: syncs community_id ↔ organization_id if one is set
- ❌ CRITICAL: If both are null during create, NO ERROR is thrown
- ❌ This allows tenantless records to be created if neither field is set

### Vulnerabilities Summary

1. **BlogPost can be tenantless** — No BelongsToTenantScope, can create/update without organization context
2. **Loop can be tenantless** — No tenant scope at all, relies on manual controller checks
3. **Factories create tenantless test data** — Default definitions don't set organization_id/community_id
4. **TransactionController bypasses scope** — Uses withoutGlobalScope then manual community_id copy
5. **HasOrganizationId allows tenantless if both null** — No validation error on create

---

# Handoffs

## Handoff aux tâches futures

### 1. Factories tenantless par défaut
- ServiceFactory, ServiceRequestFactory, TransactionFactory, LoopFactory créent des records `community_id=null`
- Hors scope T075.15 — ne pas modifier dans cette tâche
- Toute évolution : utiliser le type `Organization` (pas `Community`)
- Pas de `forOrganization(Community $org)`

### 2. BlogPost sans BelongsToTenantScope
- `BlogPost::create()` direct peut produire un record tenantless
- Contrôleurs safe (couverts par T0756) mais model orphelin de scope global
- Hors scope T075.15 — nécessite revue dédiée (impact admin views, seeders, jobs, exports)

### 3. Loop sans organization_id
- Loop ≠ Tenant par architecture — hors scope toute migration Organization
- Aucune tâche future prévue dans la roadmap actuelle

### 4. $fillable globaux community_id / organization_id
- Restent dans $fillable de tous les models tenant-scopés
- Mitigé : contrôleurs ne propagent jamais ces champs depuis la request
- Évolution possible : passer en $guarded après audit complet des créations directes

# Tests

- [x] PHPUnit — guard tenantless / cross-Organization sur TransactionController::store (T07515TransactionTenantSafetyTest) — 5/5
- [x] PHPUnit — fixtures TransactionControllerTest réalignées Organization-first (no regression) — 7/7
- [x] PHPUnit — T0755ServicesRequestsTenantSafetyTest no regression — 6/6
- [x] PHPUnit — TransactionStateMachineTest no regression — 13/13
- [x] Full suite — 660 passed, 1422 assertions
- [ ] PHPUnit — guards tenantless fillable globaux (handoff futur)
- [ ] PHPUnit — factories legacy community vs organization (handoff futur)

---

# Test Results

## Targeted tests (31 passed, 57 assertions)
```
php artisan test --filter="T07515|TransactionControllerTest|TransactionStateMachineTest|T0755"
Tests:    31 passed (57 assertions)
Duration: 1.44s
```

- T07515TransactionTenantSafetyTest: 5/5 ✅
- T0755ServicesRequestsTenantSafetyTest: 6/6 ✅
- TransactionControllerTest: 7/7 ✅
- TransactionStateMachineTest: 13/13 ✅

## Full suite (660 passed, 1422 assertions)
```
php artisan test
Tests:    660 passed (1422 assertions)
Duration: 19.48s
```

## Pint dirty
```
vendor/bin/pint --dirty
Passed ✅
```

---

# PATCH MINIMAL T075.15

## Date : 2026-05-17 — CLAUDE

## Contexte

Audit OPENAI rendu en read-only — verdict **PATCH RECOMMENDED** sur
`TransactionController::store` :

- bypass `BelongsToTenantScope` justifié mais non-gardé ;
- pas de garde explicite contre cible tenantless (`community_id` null) ;
- pas de garde explicite contre cible cross-Organization.

Décision : appliquer le patch minimal sur le seul flux faillible identifié
(création de Transaction côté web), sans toucher BlogPost, factories,
$fillable globaux, ou Loop. Ces points restent en handoff.

## Patch appliqué

### app/Http/Controllers/TransactionController.php (store)

Ajouts en haut du flux `store` :

1. Résolution `currentOrganization()` au tout début ;
2. `abort(404)` si aucune Organization résolue ;
3. Après `Service::withoutGlobalScope(...)->findOrFail($id)` :
   - `abort(404)` si `service->community_id === null` ;
   - `abort(404)` si `service->community_id !== organization->id` ;
4. Symétrique pour `ServiceRequest` (request_id).

Le reste du flux métier est strictement préservé :
self-transaction, solde insuffisant, doublon pending/accepted,
ServiceRequest `in_progress`, message système, rôles buyer/seller.

`community_id` est toujours copié depuis l'entité parente
(Service ou ServiceRequest) — après le guard, donc il est garanti
non-null et égal à `currentOrganization()->id`. Le trait
`HasOrganizationId` continue à backfiller `organization_id`.

### tests/Feature/T07515TransactionTenantSafetyTest.php (nouveau)

Tests P0 ajoutés :

- `test_web_transaction_store_rejects_service_outside_resolved_organization`
  Organization A résolue + service Org B → POST → 404, aucune transaction.
- `test_web_transaction_store_rejects_tenantless_service`
  Organization A résolue + service `community_id=null` (forcé via
  `forceFill + saveQuietly` pour simuler la faille fixture/legacy) →
  POST → 404, aucune transaction.
- `test_web_transaction_store_creates_transaction_when_service_matches_resolved_organization`
  Sentinelle positive — vérifie qu'un service dans l'Org résolue
  fonctionne toujours et que la transaction porte `community_id`
  correctement.

### tests/Feature/TransactionControllerTest.php (fixtures mises à jour)

Les trois tests de création de transaction utilisaient `Service::factory()`
sans `community_id` → tenantless. Le patch contrôleur les aurait
fait basculer en 404. Fixtures alignées :
`forUser($seller)` + `create(['community_id' => $this->testOrganization->id])`,
users dotés de `community_id => $this->testOrganization->id`.

Aucune modification du comportement métier testé.

## Tests lancés

```
php artisan test --filter="T0755|TransactionStateMachineTest|T07515|TransactionControllerTest"
Tests:    22 passed (44 assertions)
Duration: 0.93s
```

Détail :

- TransactionControllerTest : 7/7 ✅
- T07515TransactionTenantSafetyTest : 3/3 ✅
- T0755ServicesRequestsTenantSafetyTest : 6/6 ✅
- TransactionStateMachineTest : 13/13 ✅

`vendor/bin/pint --dirty --format agent` : passed.

## Risques restants (handoff futur)

1. **Factories tenantless par défaut** — `ServiceFactory`,
   `ServiceRequestFactory`, `TransactionFactory`, `LoopFactory`
   créent toujours des records `community_id=null` sans state
   explicite. Le patch contrôleur protège le flux web mais pas
   les usines de test. Handoff : helper `forOrganization()` ou
   default sur les factories (à cadrer hors T075.15).

2. **BlogPost sans BelongsToTenantScope** — `BlogPost::create()`
   direct peut produire un record tenantless. Contrôleurs actuels
   sont safe (couverts par T0756), mais le model est orphelin de
   scope global. Handoff : ajouter scope + tests négatifs.

3. **TransactionController bypass scope intentionnel** — Le
   `withoutGlobalScope(BelongsToTenantScope::class)` reste en
   place. Justifié par le besoin de fetch Service/ServiceRequest
   sans dépendre du scope au moment du lookup. Le patch ajoute
   le check explicite immédiatement après le fetch. La logique
   reste défensive même si un jour le scope est retiré.

4. **Loop sans organization_id** — Loop ≠ Tenant par architecture,
   hors scope T075.15.

5. **$fillable globaux** — `community_id` / `organization_id`
   restent dans `$fillable` de tous les models tenant-scopés.
   Le risque mass-assignment est mitigé par le fait que les
   contrôleurs (Service, Request, désormais Transaction) ne
   propagent jamais ces champs depuis la request. Handoff
   éventuel : passer `community_id` en `$guarded` ou supprimer
   du `$fillable`, mais cela nécessite un audit de toutes les
   créations directes (factories, seeders, jobs).

## Git status

```
 M app/Http/Controllers/TransactionController.php
 M tests/Feature/TransactionControllerTest.php
?? TODO/TASK-094-t075-15-fillable-tenantless-validation-guard.md
?? tests/Feature/T07515TransactionTenantSafetyTest.php
```

Aucun commit. Aucun push. Aucun merge.

---

# Review Notes

## OPENAI Final Review — APPROVED

**Verdict**: APPROVE
**Blocking issues**: Aucun

### Validation points
- Les deux tests ServiceRequest demandés sont présents :
  - `test_web_transaction_store_rejects_service_request_outside_resolved_organization`
  - `test_web_transaction_store_rejects_tenantless_service_request`
- Couverture P0 complète (Service cross-Org, Service tenantless, ServiceRequest cross-Org, ServiceRequest tenantless, happy path)
- `TransactionController::store` conforme :
  - `currentOrganization()` requis
  - 404 sans Organization
  - 404 si Service/ServiceRequest tenantless
  - 404 si Service/ServiceRequest cross-Organization
- Patch minimal confirmé (pas de BlogPost, factories, migration, routes/API/Policies, current_community, ResolveCommunity, refactor global)
- TASK file propre (BlogPost scope hors scope, `forOrganization(Community $org)` hors scope / à ne pas faire)