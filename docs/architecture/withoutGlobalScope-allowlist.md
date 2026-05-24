# withoutGlobalScope Allowlist

Date: 2026-05-24 Europe/Paris  
Référence: T129 — TASK-129-t129-withoutglobalscope-allowlist-guard-tests  
Audits source: T124, T128

---

## Principe

Ce document est la source de vérité pour tous les usages de `withoutGlobalScope` / `withoutGlobalScopes` dans le code runtime.

Chaque bypass est classé, justifié, et son niveau de risque est documenté.

**Règle d'or :** tout nouveau bypass `withoutGlobalScope` introduit dans le code runtime doit être ajouté ici avant merge. Un bypass non documenté est suspect par définition.

---

## Inventaire complet

### 1. TransactionController — bypass ciblé, LÉGITIME, TESTÉ

**Fichier :** `app/Http/Controllers/TransactionController.php:38,48`  
**Classification :** compat technique — bypass ciblé avec re-validation immédiate  
**Risque :** FAIBLE  
**Tests :** `tests/Feature/T07515TransactionTenantSafetyTest.php` (5 tests, 5 verts)

```php
// Ligne 38
$service = Service::withoutGlobalScope(BelongsToTenantScope::class)->findOrFail($data['service_id']);
if ($service->community_id === null || $service->community_id !== $organization->id) {
    abort(404);
}

// Ligne 48
$serviceReq = ServiceRequest::withoutGlobalScope(BelongsToTenantScope::class)->findOrFail($data['request_id']);
if ($serviceReq->community_id === null || $serviceReq->community_id !== $organization->id) {
    abort(404);
}
```

**Justification :** La règle de validation `exists:services,id` passe via Query Builder et ne tient pas compte du scope Eloquent. Un `service_id` valide en DB mais hors scope serait rejeté par `findOrFail` avec scope actif. Le bypass permet un `findOrFail` inconditionnel suivi d'une re-validation explicite sur `community_id`. Le bypass est ciblé (`withoutGlobalScope(BelongsToTenantScope::class)`), pas global (`withoutGlobalScopes()`).

**Verdict T129 :** Comportement correct confirmé par les 5 tests existants. Aucun patch nécessaire.

---

### 2. AdminReferralController — vue globale admin, LÉGITIME

**Fichier :** `app/Http/Controllers/Admin/AdminReferralController.php:16-48`  
**Classification :** admin plateforme — vue globale intentionnelle  
**Risque :** CONDITIONNEL (risque si admin devient Organization-scoped)  
**Tests :** non couverts par tests de garde (admin protégé par middleware admin)

```php
$totalReferrals = Referral::withoutGlobalScope(BelongsToTenantScope::class)->count();
// ... 11 autres occurrences du même pattern
```

**Justification :** Les routes admin (`/admin/*`) sont protégées par middleware d'authentification admin plateforme. Un admin plateforme a un accès cross-Organization légitime pour les dashboards et statistiques. Le bypass est intentionnel et documenté.

**Condition de reclassification :** Si un admin Organization-scoped est introduit (admin qui ne voit que son org), ce bypass deviendrait dangereux et devrait être restreint.

**Verdict T129 :** Acceptable en l'état. À surveiller si l'admin devient multi-niveau.

---

### 3. HomeController — compat legacy, DETTE, re-filtre explicite

**Fichier :** `app/Http/Controllers/HomeController.php:46-49,76`  
**Classification :** compat legacy — `withoutGlobalScopes()` (global) + re-filtre manuel  
**Risque :** MOYEN — `withoutGlobalScopes()` est plus large que nécessaire  
**Tests :** `tests/Feature/T0754DashboardMembersExchangesTenantSafetyTest.php` (4 tests verts)

```php
// Lignes 46-49 — membres et compteurs
'services as active_services_count' => fn ($q) => $q->withoutGlobalScopes()->where('status', 'active')->where('community_id', $communityId),
'serviceRequests as open_requests_count' => fn ($q) => $q->withoutGlobalScopes()->where('status', 'open')->where('community_id', $communityId),
->with(['services' => fn ($q) => $q->withoutGlobalScopes()->where('status', 'active')->where('community_id', $communityId)->with('skills', 'category')])

// Ligne 76 — échanges
$exchanges = Transaction::withoutGlobalScopes()
    // ... puis re-filtre community_id
```

**Justification :** Le bypass est utilisé dans le contexte d'eager loading de relations sur des membres. `withoutGlobalScopes()` est plus large que `withoutGlobalScope(BelongsToTenantScope::class)`, ce qui bypasse potentiellement d'autres scopes si ajoutés. La re-validation sur `community_id` compense.

**Dette technique :** Remplacer `withoutGlobalScopes()` par `withoutGlobalScope(BelongsToTenantScope::class)` ou supprimer le bypass et utiliser le scope standard.

**Verdict T129 :** Acceptable à court terme grâce aux tests T0754. À nettoyer en étape 3 de la roadmap T128.

---

### 4. Explorer Livewire — CORRIGÉ T127, SAFE

**Fichier :** `app/Livewire/Explorer.php:134,190`  
**Classification :** compat legacy corrigée — `withoutGlobalScopes()` + re-filtre serveur  
**Risque :** FAIBLE (corrigé T127)  
**Tests :** `tests/Feature/Livewire/T126ExplorerTenantScopingTest.php` (6 tests verts)

```php
$query = Service::withoutGlobalScopes()
    ->where('status', 'active')
    ->where('community_id', $communityId); // $communityId depuis currentOrganization() côté serveur (T127)
```

**Justification :** Le bypass était nécessaire pour des raisons de relations eager. Le risque de tampering Livewire a été corrigé par T127 : `$communityId` est maintenant une variable locale résolue côté serveur, pas la propriété publique `$this->communityId`.

**Verdict T129 :** Safe. Tests de tampering verts. À terme, supprimer le bypass et utiliser le scope standard.

---

### 5. DemoSeeder — hors runtime utilisateur, ACCEPTABLE

**Fichier :** `database/seeders/DemoSeeder.php:382,386`  
**Classification :** seeder uniquement — hors runtime utilisateur  
**Risque :** N/A

```php
$existing = Transaction::withoutGlobalScopes()->where($match)->first();
$tx = Transaction::withoutGlobalScopes()->create(array_merge($match, $data));
```

**Justification :** Usage dans un seeder de démo non exécuté en production par les utilisateurs. Le bypass est nécessaire pour créer des données cross-tenant dans un environnement de démo.

**Verdict T129 :** Acceptable.

---

## Tableau récapitulatif

| Surface | Fichier | Classification | Risque | Tests | Action |
|---|---|---|---|---|---|
| Transaction service lookup | `TransactionController.php:38` | Compat technique ciblé | FAIBLE | ✓ T07515 (5 verts) | Aucune |
| Transaction request lookup | `TransactionController.php:48` | Compat technique ciblé | FAIBLE | ✓ T07515 (5 verts) | Aucune |
| Admin referrals global | `AdminReferralController.php:16-48` | Admin plateforme intentionnel | CONDITIONNEL | Non requis | Surveiller si admin multi-niveau |
| Home members/services | `HomeController.php:46-49` | Legacy, re-filtre explicite | MOYEN | ✓ T0754 (4 verts) | Étape 3 roadmap T128 |
| Home exchanges | `HomeController.php:76` | Legacy, re-filtre explicite | MOYEN | ✓ T0754 | Étape 3 roadmap T128 |
| Explorer services | `Explorer.php:134` | Legacy corrigé T127 | FAIBLE | ✓ T126 (6 verts) | À terme, supprimer bypass |
| Explorer requests | `Explorer.php:190` | Legacy corrigé T127 | FAIBLE | ✓ T126 (6 verts) | À terme, supprimer bypass |
| DemoSeeder | `DemoSeeder.php:382,386` | Seeder hors runtime | N/A | N/A | Aucune |

---

## Règles de gouvernance pour les nouveaux bypass

### Autorisation par défaut : NON

Tout nouveau bypass `withoutGlobalScope` / `withoutGlobalScopes` dans le code runtime est interdit par défaut.

### Processus d'autorisation

1. Documenter la justification dans ce fichier avant le commit.
2. Utiliser `withoutGlobalScope(BelongsToTenantScope::class)` plutôt que `withoutGlobalScopes()` quand possible.
3. Ajouter immédiatement un re-filtre tenant explicite dans la même méthode.
4. Ajouter un test de garde démontrant l'isolation.
5. Valider avec COCKPIT avant merge.

### Bypass interdits sans validation

- Bypass global `withoutGlobalScopes()` dans un nouveau contexte (utiliser le ciblé)
- Bypass dans un contrôleur accessible sans authentification
- Bypass dans du code Livewire avec propriété publique comme filtre tenant
- Bypass sans re-validation tenant immédiate

---

## Contexte

- **Audit source :** `docs/audits/T124-tenant-scope-residual-risk-audit.md`
- **Stratégie source :** `docs/audits/T128-tenant-id-source-of-truth-strategy.md`
- **Scope global appliqué :** `BelongsToTenantScope` filtre sur `community_id`
- **Période de validité :** pendant la migration dual-write `community_id` → `organization_id`
- **À mettre à jour lors de :** ajout d'un nouveau bypass, correction d'un bypass existant, basculement du scope vers `organization_id`
