---
task_id: TASK-142
title: T140.3 current_community fallback gates + plan de retrait

status: DONE

owner: OPCODE

contributors: []

branch: TASK-142-t140-3-current-community-fallback-gates

priority: MEDIUM

created_at: 2026-05-24 20:08:00 Europe/Paris
updated_at: 2026-05-24 20:08:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: OPCODE
  since: 2026-05-24 20:08:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# T140.3 — Gates current_community + préparation suppression future

## Statut

IN_PROGRESS

## Objectif

- current_community n'est PAS supprimé
- T140.3 couvre et documente le fallback
- Suppression future seulement après migration callers + routes + API + views
- Aucun changement runtime
- Aucun changement DB
- Aucun changement routes

## Pré-requis validés

- T139.1 audit terminé
- T139.2 characterization gates terminé
- T140.2 loops.organization_id terminé
- T140.1 BelongsToTenantScope filtre désormais sur organization_id
- Community reste legacy temporaire
- Organization extends Community
- La table physique reste communities
- current_community est une compat temporaire intentionnelle

## Interdits absolus

Ne PAS modifier :
- app/Support/Tenancy/CurrentOrganization.php
- app/Http/Middleware/ResolveCommunity.php
- app/Http/Middleware/ResolveOrganization.php
- app/Http/Middleware/ResolveUrlOrganization.php
- app/Http/Middleware/ResolveApiOrganization.php
- app/Models/Scopes/BelongsToTenantScope.php
- routes/web.php, routes/api.php, routes/channels.php
- app/Http/Controllers/*
- app/Services/*
- app/Models/*
- database/migrations/*, database/schema/*
- .env, VERSION
- resources/views/*

## Travail effectué

### 2026-05-24 — Sous-agents A/B/C/D + synthèse pré-flight

4 sous-agents exécutés en parallèle :
- **A** : Inventaire complet current_community/currentCommunity (27 fichiers, 3 runtime binds, 1 seul lecteur)
- **B** : Audit runtime binding (CurrentOrganization, 3 middlewares, BelongsToTenantScope)
- **C** : Audit tests existants (14 tests casseraient si current_community supprimée)
- **D** : Audit vues Blade ($currentCommunity = dead code dans 3 vues, LOW risk)

Conclusion : `current_community` est une clé container write-only avec un seul lecteur (`CurrentOrganization::get()`). Safe à documenter et gatekeeper, pas à supprimer dans cette tâche.

### 2026-05-24 — Tests de gates T140.3 créés

- `tests/Feature/T1403CurrentCommunityFallbackGatesTest.php` : 6 tests
- `tests/Feature/T1392KnownRisksTest.php` : +3 known-risks (8, 9, 10)
- Suite complète : 788 passed, 8 skipped, 0 failures

## Tests ajoutés

### T1403CurrentCommunityFallbackGatesTest (6 tests)

1. `test_current_organization_takes_priority_over_current_community` — Bind org + community différents, vérifie priorité org
2. `test_current_community_fallback_still_works_when_current_organization_missing` — Caractérise fallback legacy
3. `test_current_organization_returns_null_when_no_binding_exists` — Null safety
4. `test_resolve_community_binds_both_legacy_and_current_names` — Route middleware bind les deux
5. `test_navigation_renders_with_legacy_current_community_fallback` — Gate UI Blade (navigation)
6. `test_no_new_current_community_runtime_usage_outside_allowlist` — Gate statique allowlist

### Known-risks ajoutés (T1392KnownRisksTest)

- **Risk 8** : View variable currentCommunity ne devrait plus être partagée (T140.6)
- **Risk 9** : ResolveUrlOrganization ne devrait pas binder current_community (T140.5)
- **Risk 10** : ResolveCommunity devrait être déprécié après migration (T140.9)

## Fichiers modifiés

- `tests/Feature/T1403CurrentCommunityFallbackGatesTest.php` (NOUVEAU, 6 tests)
- `tests/Feature/T1392KnownRisksTest.php` (3 nouveaux known-risks)
- `TODO/TASK-142-t140-3-current-community-fallback-gates.md` (ce fichier)
- `docs/audits/T140.3-current-community-fallback-gates.md` (créé)
- `ai/workflows/tenant-safety.md` (clarification)

## Résultat des tests

```
php artisan test --filter=T1403CurrentCommunityFallbackGatesTest => 6 passed (13 assertions)
php artisan test --filter=T1392KnownRisksTest                   => 2 passed, 8 skipped
php artisan test --filter=CurrentOrganization                   => 11 passed
php artisan test --filter=T1392                                 => 30 passed, 7 skipped
php artisan test --filter=T1401                                 => 2 passed
php artisan test                                                => 788 passed, 8 skipped
```

Aucun runtime modifié. Aucune régression.

## Plan de retrait futur (documenté seulement)

1. T140.4 : créer routes /org/{organization} en parallèle
2. T140.5 : migrer controllers/services/API/channels
3. T140.6 : migrer tests et vues
4. T140.8 : déprécier routes /{community}
5. T140.9 : retirer current_community, Community.php, colonnes legacy, table rename

## Prochaine tâche recommandée

T140.4 — Créer routes /org/{organization} en parallèle des routes /{community}
