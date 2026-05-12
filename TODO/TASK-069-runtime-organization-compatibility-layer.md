---
task_id: TASK-069
title: runtime-organization-compatibility-layer

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-069-runtime-organization-compatibility-layer

priority: MEDIUM

created_at: 2026-05-12 19:34:20 Europe/Paris
updated_at: 2026-05-12 19:38:20 Europe/Paris

labels:
  - runtime
  - organization
  - compatibility

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

Stabiliser officiellement la couche runtime Organization-compatible.
Préparation progressive de la transition Community → Organization sans casser le runtime existant.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [x] validate UI

---

# Audit Summary

## Middleware
- `app/Http/Middleware/ResolveCommunity.php` — bind à la fois `current_community` et `current_organization`
- Résout à la fois `{community}` et `{organization}` route params
- Share `currentCommunity` et `currentOrganization` aux vues
- ✅ Testé : résolution, 404, view sharing, same instance

## Middleware Aliases (`bootstrap/app.php`)
- `'community' => ResolveCommunity::class` — inchangé
- `'organization' => ResolveOrganization::class` — mis à jour depuis `ResolveCommunity::class`

## Runtime Resolution
- `app/Support/Tenancy/CurrentOrganization` — classe utilitaire avec fallback `current_organization` → `current_community`
- `currentOrganization()` helper global (autoloaded via composer.json)
- `BelongsToTenantScope` utilise `CurrentOrganization::get()` pour la résolution tenant
- ✅ Testé : préférence Organization, fallback Community, null

## Modèles
- `Organization extends Community` (même table `communities`)
- `HasOrganizationId` trait sur User, Service, ServiceRequest, Transaction, BlogPost
- `BelongsToTenantScope` sur Service, ServiceRequest, Transaction
- Tous les modèles exposent `community()` ET `organization()` relations

## Routes
- Routes community-prefixed via `/{community}` + middleware `['web', 'community']`
- `$organizationConstraint` défini pour future utilisation `/org/{organization}`
- Aucune route `/org/{organization}` activée (hors scope)

---

# Architecture Decisions

1. `ResolveOrganization` extends `ResolveCommunity` — additive, pas de changement de comportement
2. L'alias `organization` pointe vers `ResolveOrganization` (était `ResolveCommunity`) — même comportement, nom sémantique correct
3. Aucune suppression Community — compatibilité totale préservée
4. Aucune migration DB, route, ou modèle

---

# Progress Log

## 2026-05-12 19:34:20 Europe/Paris

Task created.

## 2026-05-12 19:38:20 Europe/Paris

Audit terminé :
- ResolveCommunity middleware ✅
- Runtime bindings current_community / current_organization ✅
- BelongsToTenantScope ✅
- Middleware aliases ✅
- Routes ✅
- Modèles (Organization, Community, HasOrganizationId) ✅
- Helpers (CurrentOrganization, currentOrganization()) ✅

Implémentation :
- `app/Http/Middleware/ResolveOrganization.php` créé (extends ResolveCommunity)
- `bootstrap/app.php` mis à jour (alias → ResolveOrganization)
- `tests/Feature/OrganizationCompatibilityTest.php` étendu (18 → 22 tests)
- `tests/Feature/OrganizationRouteCompatibilityTest.php` étendu (8 → 9 tests)
- `tests/Feature/CurrentOrganizationTest.php` créé (9 tests)
- Pint exécuté

Validation :
- Full test suite : 310 passed, 621 assertions
- Zéro régression

---

# Modified Files

- `app/Http/Middleware/ResolveOrganization.php` — NOUVEAU
- `bootstrap/app.php` — alias organisation pointé vers ResolveOrganization
- `tests/Feature/OrganizationCompatibilityTest.php` — +4 tests (middleware compat, no regression)
- `tests/Feature/OrganizationRouteCompatibilityTest.php` — +1 test (alias consistency)
- `tests/Feature/CurrentOrganizationTest.php` — NOUVEAU (9 tests: CurrentOrganization + helper)

---

# Test Coverage

## OrganizationCompatibilityTest (22 tests)
- Organization model: extends Community, table, factory, slug, inactive
- Middleware: ResolveCommunity binds, same instance, 404
- Aliases: organization registered, community unchanged
- ResolveOrganization: extends, alias points, binds both keys, 404, view share
- Regression: ResolveCommunity legacy behavior unchanged (full validation)

## OrganizationRouteCompatibilityTest (9 tests)
- Route param resolution: {organization}, {community}, both keys
- Error cases: unknown slug, inactive
- Route key: Organization slug, Community id
- Alias consistency: community + organization resolve same tenant

## CurrentOrganizationTest (9 tests)
- CurrentOrganization::get(): org bound, community fallback, precedence, null
- CurrentOrganization::id(): returns id, null
- currentOrganization() helper: org bound, fallback, null

## BelongsToTenantScopeTest (8 tests)
- Organization precedence, combined binding, community fallback
- No binding = no scope
- Applies to Service/ServiceRequest/Transaction
- withoutGlobalScope bypass
- Organization model scopes identically to Community

---

# Test Results

All 310 tests passed (621 assertions). Full suite vert.

---

# Review Notes

- Additif uniquement — aucune suppression
- Aucun breaking change
- Aucune migration DB
- Aucune migration route
- Comportement runtime inchangé
- Aliases `community` et `organization` résolvent la même instance tenant
- Prêt pour finalize-task.sh + merge-task.sh (géré par OPS)

---

# Review Notes

Pending.