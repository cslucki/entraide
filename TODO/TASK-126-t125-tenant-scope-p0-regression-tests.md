---
task_id: TASK-126
title: t125-tenant-scope-p0-regression-tests

status: DONE

owner: CODEX

contributors: []

branch: TASK-126-t125-tenant-scope-p0-regression-tests

priority: HIGH

created_at: 2026-05-23 22:02:40 Europe/Paris
updated_at: 2026-05-23 22:10:00 Europe/Paris

labels:
  - tenant
  - organization
  - p0-regression
  - livewire
  - security

lock:
  status: UNLOCKED
  agent: CODEX
  since: 2026-05-23 22:10:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Ajouter des tests de non-régression P0 tenant-scope avant tout refactor tenant,
en se basant sur l'audit T124 (docs/audits/T124-tenant-scope-residual-risk-audit.md).

Scope :
1. Explorer Livewire tenant isolation (communityId public property tampering)
2. Profile reviews cross-org (reviewsReceived() sans filtre org)
3. Désynchronisation community_id != organization_id (scope vs policy divergence)

Contraintes respectées :
- pas de migration
- pas de seed destructif
- pas de modification runtime
- pas de refactor BelongsToTenantScope
- pas de cleanup withoutGlobalScope
- pas de main/PROD

---

# Planned Actions

- [x] lire docs/audits/T124-tenant-scope-residual-risk-audit.md
- [x] inspecter Explorer.php (mount + render queries)
- [x] inspecter ProfileController.php (reviewsReceived)
- [x] inspecter BelongsToTenantScope + ServicePolicy (divergence)
- [x] créer T126ExplorerTenantScopingTest.php
- [x] créer T126ProfileReviewsTenantScopingTest.php
- [x] créer T126DesyncCommunityOrganizationIdTest.php
- [x] lancer les tests ciblés
- [x] documenter résultats dans TASK file
- [x] commit + push
- [ ] validation COCKPIT avant merge

---

# Progress Log

## 2026-05-23 22:02:40 Europe/Paris

Task créée. Branch : TASK-126-t125-tenant-scope-p0-regression-tests.

## 2026-05-23 22:08:00 Europe/Paris

Lecture de l'audit T124. 3 risques P0 ciblés :
- Explorer::$communityId propriété publique Livewire susceptible de tampering
- reviewsReceived() sans filtre org sur profil public
- divergence community_id != organization_id entre BelongsToTenantScope (filtre community_id)
  et ServicePolicy (vérifie organization_id)

Fichiers inspectés :
- app/Livewire/Explorer.php (mount, render, withoutGlobalScopes)
- app/Http/Controllers/ProfileController.php (reviewsReceived, ligne 30)
- app/Models/Scopes/BelongsToTenantScope.php
- app/Policies/ServicePolicy.php
- app/Models/User.php (reviewsReceived hasMany Review)
- app/Models/Review.php (pas de community_id / organization_id)
- Tests existants pour pattern (T0754, T0757, HasOrganizationIdTest, BelongsToTenantScopeTest)

## 2026-05-23 22:10:00 Europe/Paris

Tests créés et exécutés. Résultats : 4 risques confirmés (tests rouges).

---

# Tests

- [x] feature tests (17 tests, 13 passed, 4 failed intentionnels = risques confirmés)
- [ ] browser validation (hors scope — tests headless suffisants)
- [ ] responsive validation (hors scope)
- [ ] console inspection (hors scope)
- [x] tenant validation (isolation multi-org validée pour le comportement normal)

---

# Test Results

## Commande

```bash
php artisan test tests/Feature/Livewire/T126ExplorerTenantScopingTest.php \
  tests/Feature/T126ProfileReviewsTenantScopingTest.php \
  tests/Feature/T126DesyncCommunityOrganizationIdTest.php
```

## Résultat global : 13 passed / 4 failed — Duration: 1.51s

---

## T126ExplorerTenantScopingTest (6 tests)

| Test | Résultat | Signification |
|---|---|---|
| explorer_shows_services_from_current_organization | ✓ PASS | Isolation normale OK |
| explorer_does_not_show_services_from_other_organization | ✓ PASS | Isolation normale OK |
| explorer_requests_tab_does_not_show_requests_from_other_organization | ✓ PASS | Isolation normale OK |
| explorer_mount_initializes_community_id_from_current_organization | ✓ PASS | Mount correct |
| **explorer_tampering_community_id_does_not_expose_cross_org_services** | **✗ FAIL** | **RISQUE P0 CONFIRMÉ** |
| **explorer_tampering_community_id_does_not_expose_cross_org_requests** | **✗ FAIL** | **RISQUE P0 CONFIRMÉ** |

**Risque Explorer CONFIRMÉ** :
`communityId` est une propriété publique Livewire. Via `->set('communityId', $orgB->id)`,
un attaquant peut forcer le composant à afficher les services/requests d'une autre organization.
`withoutGlobalScopes()` bypasse BelongsToTenantScope, et le seul filtre est `where('community_id', $this->communityId)`.
Si `$this->communityId` est tampered, les données cross-org sont exposées.

---

## T126ProfileReviewsTenantScopingTest (3 tests)

| Test | Résultat | Signification |
|---|---|---|
| profile_shows_reviews_from_same_organization | ✓ PASS | Cas normal OK |
| **profile_does_not_show_reviews_from_other_organization_transactions** | **✗ FAIL** | **RISQUE P1 CONFIRMÉ** |
| **profile_shows_only_reviews_scoped_to_current_organization** | **✗ FAIL** | **RISQUE P1 CONFIRMÉ** |

**Risque Profile Reviews CONFIRMÉ** :
`reviewsReceived()` est un `hasMany(Review::class, 'reviewed_id')` sans filtre org.
La table `reviews` n'a pas de `community_id`/`organization_id`.
ProfileController charge `$user->reviewsReceived()` sans scope org.
Un user membre de OrgA ayant participé à des transactions dans OrgB verra ses reviews
cross-org apparaître sur son profil consulté depuis OrgA.

---

## T126DesyncCommunityOrganizationIdTest (8 tests)

| Test | Résultat | Signification |
|---|---|---|
| synced_service_is_visible_in_scope_and_authorized_in_policy | ✓ PASS | Baseline OK |
| desync_community_a_org_b_is_visible_in_org_a_scope | ✓ PASS | Scope sur community_id : visible (**divergence attendue**) |
| desync_community_a_org_b_policy_blocks_update | ✓ PASS | Policy sur organization_id : bloquée (**divergence attendue**) |
| desync_community_a_org_b_creates_scope_policy_divergence | ✓ PASS | Divergence documentée |
| desync_community_b_org_a_is_invisible_in_org_a_scope | ✓ PASS | Service invisible dans OrgA scope |
| desync_community_b_org_a_policy_would_authorize_if_accessible | ✓ PASS | Policy autorise (org_id = OrgA) même si invisible |
| service_with_null_community_id_is_excluded_by_scope | ✓ PASS | Fail-closed sur NULL |
| creating_service_via_model_keeps_columns_synced | ✓ PASS | HasOrganizationId fonctionne |

**Divergence scope/policy documentée (attendue)** :
- `BelongsToTenantScope` filtre sur `community_id`
- `ServicePolicy::resourceBelongsToCurrentOrganization()` vérifie `organization_id`
- Cas `community_id = OrgA, organization_id = OrgB` : service visible dans listing OrgA,
  mais policy bloque update/delete pour un user OrgA. Comportement incohérent mais non exploitable
  directement depuis le listing (le service apparaît mais ne peut pas être modifié).
- Le risque est limité aux données historiques ou imports directs qui bypasse HasOrganizationId.
  En création normale via modèle, les deux colonnes sont synchronisées.

---

# Review Notes

## Risques confirmés par les tests

### P0 — Explorer communityId tampering (RISQUE ACTIF)

**Fichier** : `app/Livewire/Explorer.php`
**Ligne** : mount():81, render():131, 187

`communityId` est `public ?string $communityId = null;` — propriété publique Livewire.
Livewire 4 accepte les mises à jour de propriétés publiques depuis le client.
Le composant utilise `withoutGlobalScopes()` puis `where('community_id', $this->communityId)`.
Un attaquant peut envoyer un update Livewire avec `communityId = <autre_org_id>` pour voir
les services/requests d'une autre organization.

**Patch recommandé (à valider COCKPIT avant T127)** :
Option A — Recompute côté serveur dans `render()` :
```php
public function render()
{
    // Recalcul serveur : empêche le tampering
    $this->communityId = currentOrganization()?->id;
    // ... reste du code inchangé
}
```

Option B — Utiliser directement currentOrganization() dans les queries sans passer par la propriété :
```php
$communityId = currentOrganization()?->id;
$query = Service::withoutGlobalScopes()
    ->where('community_id', $communityId);
```

Option A est moins invasive. Option B est plus propre à terme.

---

### P1 — Profile reviews cross-org (RISQUE ACTIF)

**Fichier** : `app/Http/Controllers/ProfileController.php`
**Ligne** : ~35 `$reviews = $user->reviewsReceived()->with('reviewer')->latest('created_at')->get();`

`reviewsReceived()` charge toutes les reviews sans filtre org.
La table `reviews` n'a pas de `community_id`/`organization_id`.

**Patch recommandé (à valider COCKPIT avant T127)** :
Filtrer les reviews par org via la transaction parente :
```php
$reviews = $user->reviewsReceived()
    ->whereHas('transaction', fn ($q) => $q->where('community_id', currentOrganization()?->id))
    ->with('reviewer')
    ->latest('created_at')
    ->get();
```

Attention : certaines reviews peuvent avoir `transaction_id = null` (ReviewFactory default).
Ajouter un `->whereNotNull('transaction_id')` ou gérer le cas null si nécessaire.

---

### P0 accepté — Désynchronisation community_id / organization_id

**Verdict** : risque limité aux données historiques ou imports DB directs.
En création normale via modèle Eloquent, `HasOrganizationId` synchronise les deux colonnes.
La divergence scope/policy documentée (scope visible, policy bloque) est incohérente
mais non exploitable directement depuis les listings normaux.

**Recommandation T127** (si patch approuvé) :
- Documenter une allowlist des usages `withoutGlobalScope` (T124 § 3)
- Ajouter un guard dans BelongsToTenantScope pour vérifier aussi `organization_id`
  quand les deux colonnes sont présentes (T124 § 5 stratégie progressive)

---

## Fichiers créés dans cette tâche

- `tests/Feature/Livewire/T126ExplorerTenantScopingTest.php` (6 tests)
- `tests/Feature/T126ProfileReviewsTenantScopingTest.php` (3 tests)
- `tests/Feature/T126DesyncCommunityOrganizationIdTest.php` (8 tests)

## Fichiers runtime modifiés

Aucun. Contrainte respectée.

## Recommandations T127

Si COCKPIT valide le patch :
1. Corriger `Explorer.php` : recompute `communityId` dans `render()` côté serveur
2. Corriger `ProfileController.php` : filtrer `reviewsReceived()` par transaction community_id
3. Les 4 tests rouges deviendront verts après patch
4. Les 8 tests de désync restent verts (comportement documenté)
