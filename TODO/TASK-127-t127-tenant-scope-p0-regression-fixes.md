---
task_id: TASK-127
title: t127-tenant-scope-p0-regression-fixes

status: DONE

owner: CODEX

contributors: []

branch: TASK-127-t127-tenant-scope-p0-regression-fixes

priority: HIGH

created_at: 2026-05-24 00:15:05 Europe/Paris
updated_at: 2026-05-24 00:30:00 Europe/Paris

labels:
  - tenant
  - organization
  - p0-fix
  - livewire
  - security

lock:
  status: UNLOCKED
  agent: CODEX
  since: 2026-05-24 00:30:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Corriger les deux risques P0/P1 confirmés par TASK-126 (tests de non-régression tenant-scope).

Cette tâche absorbe T126 : T126 ne doit PAS être mergée séparément.
Le merge se fait depuis la branche T127, qui contient à la fois les tests T126 et les patches runtime.

Risques corrigés :

1. **P0 — Explorer Livewire communityId tampering**
   - `app/Livewire/Explorer.php`
   - `communityId` est une propriété publique Livewire → tamperable côté client
   - Le composant utilisait `$this->communityId` comme source de vérité pour les requêtes
   - Fix : utiliser `currentOrganization()?->id` comme variable locale dans `render()`

2. **P1 — Profile reviews cross-org**
   - `app/Http/Controllers/ProfileController.php`
   - `reviewsReceived()` était un `hasMany(Review)` sans filtre tenant
   - Fix : filtrer via `whereHas('transaction', fn($q) => $q->where('community_id', $organization->id))`

Contraintes respectées :
- pas de modification BelongsToTenantScope
- pas de migration community_id → organization_id
- pas de correction désync community_id != organization_id
- pas de suppression withoutGlobalScope global
- pas de refactor Explorer au-delà du nécessaire
- pas de renommage Community
- pas de main / PROD

---

# Planned Actions

- [x] vérifier git status (branche TASK-126)
- [x] créer TASK-127 depuis TASK-126
- [x] lire TASK-126 (résultats, risques confirmés)
- [x] inspecter app/Livewire/Explorer.php
- [x] inspecter app/Http/Controllers/ProfileController.php
- [x] inspecter app/Models/Review.php (relation transaction)
- [x] inspecter tests/Feature/Livewire/ExplorerTest.php (tests existants, compatibilité)
- [x] appliquer Patch 1 — Explorer.php (variable locale $communityId dans render())
- [x] appliquer Patch 2 — ProfileController.php (whereHas transaction community_id)
- [x] lancer Pint
- [x] lancer tests T126 (17 tests)
- [x] lancer tests complémentaires (ExplorerTest, T0757, T0754)
- [x] mettre à jour TASK-127
- [x] commit + push

---

# Progress Log

## 2026-05-24 00:15:05 Europe/Paris

Task créée. Branch : TASK-127-t127-tenant-scope-p0-regression-fixes.
Créée depuis TASK-126-t125-tenant-scope-p0-regression-tests (branche des tests P0).

## 2026-05-24 00:30:00 Europe/Paris

Patches appliqués :

### Patch 1 — Explorer.php

Ajout d'une variable locale `$communityId = currentOrganization()?->id;` au début de `render()`.
Les deux requêtes (`Service::withoutGlobalScopes()` et `ServiceRequest::withoutGlobalScopes()`)
utilisent désormais `$communityId` au lieu de `$this->communityId`.

`$this->communityId` reste une propriété publique (initialisée dans mount() pour compatibilité)
mais n'est plus utilisée comme source de vérité tenant dans les requêtes.
Toute tentative de tampering côté client est ignorée.

### Patch 2 — ProfileController.php

Remplacement de :
```php
$reviews = $user->reviewsReceived()->with('reviewer')->latest('created_at')->get();
```
par :
```php
$reviews = $user->reviewsReceived()
    ->whereHas('transaction', fn ($q) => $q->where('community_id', $organization->id))
    ->with('reviewer')
    ->latest('created_at')
    ->get();
```

La variable `$organization` était déjà disponible ligne 20.
Les reviews sans `transaction_id` (NULL) sont exclues du profil public Organization-scoped.
Les reviews liées à des transactions d'une autre Organization sont exclues.

---

# Tests

- [x] feature tests — T126 : 17/17 verts (4 anciens rouges devenus verts)
- [x] feature tests — complémentaires : 18/18 verts (ExplorerTest, T0757, T0754)
- [ ] browser validation (hors scope — tests headless suffisants)
- [ ] responsive validation (hors scope)
- [ ] console inspection (hors scope)
- [x] tenant validation — isolation multi-org validée

---

# Test Results

## Tests T126 (cibles des patches)

```bash
php artisan test tests/Feature/Livewire/T126ExplorerTenantScopingTest.php \
  tests/Feature/T126ProfileReviewsTenantScopingTest.php \
  tests/Feature/T126DesyncCommunityOrganizationIdTest.php
```

**Résultat : 17 passed / 0 failed — Duration: 2.39s**

| Test | Avant T127 | Après T127 |
|---|---|---|
| explorer_shows_services_from_current_organization | ✓ | ✓ |
| explorer_does_not_show_services_from_other_organization | ✓ | ✓ |
| explorer_requests_tab_does_not_show_requests_from_other_organization | ✓ | ✓ |
| explorer_mount_initializes_community_id_from_current_organization | ✓ | ✓ |
| **explorer_tampering_community_id_does_not_expose_cross_org_services** | **✗ FAIL** | **✓ PASS** |
| **explorer_tampering_community_id_does_not_expose_cross_org_requests** | **✗ FAIL** | **✓ PASS** |
| profile_shows_reviews_from_same_organization | ✓ | ✓ |
| **profile_does_not_show_reviews_from_other_organization_transactions** | **✗ FAIL** | **✓ PASS** |
| **profile_shows_only_reviews_scoped_to_current_organization** | **✗ FAIL** | **✓ PASS** |
| synced_service_is_visible_in_scope_and_authorized_in_policy | ✓ | ✓ |
| desync_community_a_org_b_is_visible_in_org_a_scope | ✓ | ✓ |
| desync_community_a_org_b_policy_blocks_update | ✓ | ✓ |
| desync_community_a_org_b_creates_scope_policy_divergence | ✓ | ✓ |
| desync_community_b_org_a_is_invisible_in_org_a_scope | ✓ | ✓ |
| desync_community_b_org_a_policy_would_authorize_if_accessible | ✓ | ✓ |
| service_with_null_community_id_is_excluded_by_scope | ✓ | ✓ |
| creating_service_via_model_keeps_columns_synced | ✓ | ✓ |

## Tests complémentaires (régression)

```bash
php artisan test tests/Feature/Livewire/ExplorerTest.php \
  tests/Feature/T0757ProfileOrganizationScopingTest.php \
  tests/Feature/T0754DashboardMembersExchangesTenantSafetyTest.php
```

**Résultat : 18 passed / 0 failed — Duration: 1.76s**

| Suite | Résultat |
|---|---|
| ExplorerTest (11 tests) | ✓ PASS |
| T0757ProfileOrganizationScopingTest (3 tests) | ✓ PASS |
| T0754DashboardMembersExchangesTenantSafetyTest (4 tests) | ✓ PASS |

---

# Review Notes

## Fichiers modifiés dans T127

- `app/Livewire/Explorer.php` — Patch 1 : variable locale `$communityId` dans `render()`
- `app/Http/Controllers/ProfileController.php` — Patch 2 : filtre `whereHas('transaction')` sur `community_id`

## Fichiers créés dans T126 (absorbés par T127)

- `tests/Feature/Livewire/T126ExplorerTenantScopingTest.php` (6 tests)
- `tests/Feature/T126ProfileReviewsTenantScopingTest.php` (3 tests)
- `tests/Feature/T126DesyncCommunityOrganizationIdTest.php` (8 tests)

## T126 ne doit pas être mergée séparément

T127 intègre les tests de T126 + les corrections runtime.
T126 (TASK-126-t125-tenant-scope-p0-regression-tests) reste une branche archivée.
**Le merge se fait depuis la branche T127 uniquement.**

## Risques corrigés

### P0 — Explorer communityId tampering (CORRIGÉ)

`Explorer::render()` utilise désormais `currentOrganization()?->id` directement.
La propriété publique `$this->communityId` n'est plus la source de vérité tenant.
Un attaquant peut toujours envoyer `communityId = <orgB_id>`, mais le serveur l'ignore.

### P1 — Profile reviews cross-org (CORRIGÉ)

`ProfileController::show()` filtre les reviews via la transaction parente.
Reviews cross-org exclues. Reviews sans transaction exclues du profil public.

## Risques documentés et non corrigés dans T127

### P0 accepté — Désync community_id != organization_id

Risque limité aux données historiques / imports DB directs.
La divergence scope/policy (scope filtre community_id, policy vérifie organization_id) est documentée
dans les 8 tests de désync. Comportement incohérent mais non exploitable depuis les listings normaux.

Traitement recommandé (T128+) :
- Allowlist des usages `withoutGlobalScope`
- Guard dans BelongsToTenantScope pour vérifier organization_id quand les deux colonnes existent

## Dettes reportées (hors scope T127)

- BelongsToTenantScope : pas modifié (contrainte T127)
- withoutGlobalScope cleanup global : pas fait (contrainte T127)
- community_id → organization_id migration : pas fait (contrainte T127)
- Review model : pas de community_id ajouté (contrainte T127 — filtrage via transaction suffisant)
