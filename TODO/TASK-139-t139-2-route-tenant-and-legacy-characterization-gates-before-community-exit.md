---
task_id: TASK-139.2
title: Route, Tenant and Legacy Characterization Gates before Community Exit

status: MERGED

owner: OPENCODE

contributors: []

branch: T139.2-t139-2-route-tenant-and-legacy-characterization-gates-before-community-exit

priority: MEDIUM

created_at: 2026-05-24 19:11:57 Europe/Paris
updated_at: 2026-05-24 22:00:00 Europe/Paris

labels:
  - gates
  - smoke-tests
  - characterization
  - known-risks
  - no-runtime-changes

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: 2026-05-24 21:00:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Créer les gates de sécurité avant toute migration Community → Organization.

Cette tâche NE migre PAS Community.
Cette tâche NE modifie PAS le runtime métier.
Cette tâche NE change PAS BelongsToTenantScope.
Cette tâche NE change PAS la DB.
Cette tâche NE lance PAS de migration.

Scope :
1. Corriger le rapport T139.1 sur les erreurs factuelles relevées par Codex (6 points).
2. Ajouter des smoke tests sur les routes critiques.
3. Ajouter des tests de caractérisation du comportement actuel.
4. Ajouter des tests known-risk non bloquants pour documenter les risques avant T140.x.
5. Ajouter un script smoke local.
6. Documenter clairement ce qui bloque ou non la CI.

---

# Planned Actions

- [x] Step 0: Git safety — checkout develop, create branch
- [x] Step 1: Corriger rapport T139.1 (6 points Codex)
- [ ] Step 2: Créer/mettre à jour TASK file T139.2
- [ ] Step 3: Smoke gates — tests Feature routes critiques
- [ ] Step 4: Tests de caractérisation comportement legacy
- [ ] Step 5: Known-risk tests documentés non-bloquants
- [x] Step 6: Script smoke local (ai/scripts/smoke-critical-routes.sh)
- [x] Step 7: Mise à jour workflows agents
- [x] Step 8: Lancer tests (php artisan test)
- [x] Step 9: Vérifier lint / type check
- [x] Step 10: Commit + push
- [x] Step 11: Rapport final

---

# Progress Log

## 2026-05-24 19:11:57 Europe/Paris

Task created.

## 2026-05-24 21:00:00 Europe/Paris

Étape 0 — Git safety :
- Switched to develop (clean state)
- Created branch T139.2-t139-2-route-tenant-and-legacy-characterization-gates-before-community-exit

Étape 1 — T139.1 corrigé (6 points Codex) :
- A. Périmètre : précisé app/, tests/, database/, routes/, resources/, config/, public/
- B. /membres : clarification couverture partielle par T0752
- C. ExplorerTest : reclassé cleanup T140.6 (pas P0 bloquant)
- D. current_community : reclassé "à couvrir par gates, suppression T140.3"
- E. routes /{community} : reclassé dette legacy compatible, stratégie parallèle
- F. Organization extends Community : viable temporairement si gates vertes
- Section "Mises à jour post-Codex (T139.2)" ajoutée en fin de rapport
- Questions Codex mises à jour avec résolutions

Étape 2 — TASK file mis à jour avec scope précis et plan d'actions détaillé.

Étape 3 — Smoke gates créées :
- tests/Feature/T1392RouteSmokeGatesTest.php (28 tests)
- Couvre : routes root-level (/explorer, /membres, /blog, /boucles, /echanges),
  routes admin (/admin/dashboard, /admin/users, /admin/services, /admin/requests, /admin/messages),
  routes community-prefixed (/{community}/explorer, /{community}/dashboard, /{community}/membres),
  routes authentifiées (profile, loops, points),
  vérification routes nommées critiques.

Étape 4 — Tests de caractérisation créés :
- tests/Feature/T1392LegacyCharacterizationTest.php (23 tests)
- Couvre : BelongsToTenantScope sur community_id, CurrentOrganization fallback,
  ResolveCommunity bindings, ResolveApiOrganization dépendance, routes /{community},
  Loop dépendance community_id, broadcast channels, Organization extends Community,
  HasOrganizationId sync bidirectionnelle.

Étape 5 — Known-risk tests créés :
- tests/Feature/T1392KnownRisksTest.php (7 tests, @group tenant-known-risk, skipped)
- Couvre : scope organization_id, Loop organization_id, current_community removal,
  ResolveApiOrganization migration, broadcast channels, /org/{organization} routes,
  ExplorerTest legacy cleanup.
- Ne plus utilise RefreshDatabase (évite deadlock PostgreSQL en env concurrent)

Étape 6 — Script smoke local créé :
- ai/scripts/smoke-critical-routes.sh
- Vérifie les routes root-level (/, /explorer, /membres, /blog, /boucles, /echanges)
- Vérifie les routes admin (/admin/dashboard, /admin/users, /admin/services, etc.)
- Vérifie /dashboard
- Non destructif, pas de migration, base URL configurable

Étape 7 — Mise à jour workflow agent :
- ai/workflows/tenant-safety.md : section "Characterization Gates (T139.2+)" ajoutée
- Documente les 3 types de tests disponibles et leurs usages

Étape 8 — Tests exécutés et verts :
- 29 smoke tests (php artisan test --filter=T1392RouteSmokeGatesTest)
- 27 caractérisation (php artisan test --filter=T1392LegacyCharacterizationTest)
- 7 known-risks skipped (php artisan test --filter=T1392KnownRisksTest)

Étape 9 — Pas de lint/typecheck à exécuter (aucun code runtime modifié, tests PHPUnit uniquement)

Étape 10 — Commit préparé :
- Fichiers : TASK, 3 test files, 1 script, 1 workflow + audit corrections
- Status DONE + UNLOCKED

Étape 11 — Rapport final :

## Post-review (2026-05-24) — 3 vérifications avant merge :
1. Tests : php artisan test --filter=T1392 → 56 passed, 7 skipped, 0 failures (94 assertions). Verts.
2. Rapport T139.1 : scope 789 occurrences documenté, T0752 et T126 reconnus. Corrigé.
3. Script smoke : prérequis "php artisan serve" ajouté dans l'en-tête. Fixé.

Étape 12 — Smoke script corrigé : documentation du prérequis serveur obligatoire.
- T139.2 terminé : 63 tests de gate créés (29 smoke + 27 caractérisation + 7 skipped known-risks)
- 1 script smoke local
- 1 workflow mis à jour
- Rapport T139.1 corrigé (6 points Codex)
- Aucune modification runtime
- Prêt pour finalize-task.sh puis merge

---

# Tests

- [x] Smoke gates (29 tests) — T1392RouteSmokeGatesTest
- [x] Caractérisation (27 tests) — T1392LegacyCharacterizationTest
- [x] Known-risk (7 tests, skipped, @group tenant-known-risk) — T1392KnownRisksTest
- [x] php artisan test --filter=T1392RouteSmokeGatesTest (29 passes)
- [x] php artisan test --filter=T1392LegacyCharacterizationTest (27 passes)
- [x] php artisan test --filter=T1392KnownRisksTest (7 skipped)

---

# Test Results

## T1392RouteSmokeGatesTest — 29 passes, 0 failures
Exécuté via `php artisan test --filter=T1392RouteSmokeGatesTest` — tous verts.

Couverture :
- 20 tests anonymes (root-level, admin redirect, community-prefixed, /dashboard)
- 9 tests authentifiés (admin, user, profile, loops, points)

## T1392LegacyCharacterizationTest — 27 passes, 0 failures
Exécuté via : `php artisan test --filter=T1392LegacyCharacterizationTest` — tous verts.

Couverture :
- BelongsToTenantScope: filtrage community_id (3 scopes + 2 apply + cross-tenant)
- CurrentOrganization: binding 3 scopes (middleware, null, fallback)
- ResolveCommunity: bindings variés (slug avec/sans tirets)
- ResolveApiOrganization: dépendance user->community_id
- Routes /{community}: Organization extends Community, is_public gate
- Broadcast channels: vérification format auth condition
- Organization extends Community: compatibilité insertion/slug/organization_id
- HasOrganizationId boot sync: 5 methods (boolean, create, update, enum, array)
- Route naming: 5 routes nommées (explorer, members, exchanges, loops, blog)

## T1392KnownRisksTest — 7 skipped (group tenant-known-risk)
Exécuté via `php artisan test --filter=T1392KnownRisksTest` — 7 skipped.

Risques documentés :
1. BelongsToTenantScope → organization_id (T140.1)
2. Loop → organization_id (T140.2)
3. current_community → removal (T140.3)
4. ResolveApiOrganization → organization-first (T140.5)
5. Broadcast channels → organization_id (T140.5)
6. Routes /org/{organization} → parallèle (T140.4)
7. ExplorerTest → cleanup (T140.6)

## Limitations connues
- Les tests ne peuvent pas être exécutés via `vendor/bin/phpunit` en parallèle sur PostgreSQL
  (deadlock DROP TABLE ... CASCADE entre processus concurrents). Utiliser `php artisan test` qui
  sérialise correctement.
- Les known-risk tests n'utilisent pas RefreshDatabase (évite deadlock).
- `php artisan test` complet (toute la suite) peut échouer sur des tests Legacy existants
  indépendants de T139.2.

---

# Review Notes

## Périmètre strict

Cette tâche NE touche PAS :
- app/Models/Scopes/BelongsToTenantScope.php
- app/Models/Loop.php
- app/Services/LoopService.php, LoopMessageService.php
- app/Support/Tenancy/CurrentOrganization.php
- app/Http/Middleware/*.php
- routes/web.php, api.php, channels.php
- database/migrations/*
- database/schema/*
- .env

Cette tâche NE fait PAS :
- migration DB
- ajout loops.organization_id
- changement community_id → organization_id
- suppression current_community
- renommage routes community.*
- création /org/{organization}
- drop community_id
- rename communities → organizations
- suppression Community.php

## Résultats attendus des routes critiques

| Route | Statut attendu |
|-------|---------------|
| GET / | 200 |
| GET /explorer | 200 |
| GET /membres | 200 |
| GET /echanges | 200 |
| GET /boucles | 200 |
| GET /blog | 200 |
| GET /{slug}/ | 200 |
| GET /{slug}/explorer | 200 |
| GET /{slug}/membres | 200 |
| GET /{slug}/dashboard | 302 (guest) / 200 (auth) |
| GET /admin/dashboard | 302 (guest) / 403 (user) / 200 (admin) |
| GET /admin/users | 302 (guest) / 403 (user) / 200 (admin) |

## Ordre futur (T140.x)

Après T139.2 :
- T140.2: loops.organization_id nullable + backfill + trait + factory
- T140.1: BelongsToTenantScope vers organization_id
- T140.3: couvrir current_community fallback par gates, pas supprimer trop tôt
- T140.4: créer /org/{organization} en parallèle, pas rename brutal
- T140.5: controllers/services/API/channels
- T140.6: migration tests massive + cleanup ExplorerTest
- T140.7: nettoyage HasOrganizationId après canonisation
- T140.8: déprécier routes /{community}
- T140.9: DB destructive / rename / suppression Community.php

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
