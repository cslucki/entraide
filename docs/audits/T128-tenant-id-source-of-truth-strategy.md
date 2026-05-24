# T128 — Tenant ID Source of Truth Strategy

Date: 2026-05-24 Europe/Paris  
Branche: `TASK-128-t128-tenant-id-source-of-truth-strategy`  
Mode: audit + stratégie, sans patch runtime

---

## 1. Résumé exécutif

La plateforme est en période de migration duale `community_id` → `organization_id`.
Le concept est stabilisé : **Organization = Tenant**. Mais le runtime utilise encore deux colonnes parallèles avec des règles de synchronisation partielles, ce qui crée une divergence de source de vérité entre couches.

**Constat principal** :

- `BelongsToTenantScope` filtre sur `community_id`
- Toutes les policies vérifient `organization_id`
- `HasOrganizationId` synchronise les deux à la création/update Eloquent
- Les accès directs `DB::table()` bypassent cette synchronisation

Cette divergence est **documentée**, **bornée**, et **non exploitable directement depuis les listings normaux** — à condition que les données restent synchronisées. Le risque devient réel uniquement si `community_id != organization_id` sur un enregistrement en production.

**T127 a corrigé les deux zones d'exploitation directe identifiées** (Explorer tampering, Profile reviews).

Ce document fixe la stratégie pour la période dual-write et définit l'ordre de migration sûr vers `organization_id` comme colonne unique.

---

## 2. Questions tranchées

### Q1. Quelle colonne utilise BelongsToTenantScope ?

`community_id` exclusivement.

```php
// app/Models/Scopes/BelongsToTenantScope.php:17
$builder->where($model->getTable().'.community_id', $organization->id);
```

Fail-closed quand aucun tenant résolu : `whereRaw('0 = 1')`.

### Q2. Quelle colonne utilisent les policies ?

`organization_id` exclusivement — toutes les policies partagent le même helper :

```php
// ServicePolicy, BlogPostPolicy, TransactionPolicy, ReviewPolicy, ServiceRequestPolicy
private function resourceBelongsToCurrentOrganization($resource): bool
{
    return $resource->organization_id === $org->id;
}
```

**Divergence confirmée** : scope filtre sur `community_id`, policies vérifient `organization_id`. Si les deux colonnes divergent sur un enregistrement, un service peut être visible dans le listing (scope passe) mais rejeté en modification (policy bloque).

### Q3. Quelles tables ont dual-write community_id + organization_id ?

| Table | community_id | organization_id | HasOrganizationId | BelongsToTenantScope |
|---|---|---|---|---|
| `users` | ✓ | ✓ | ✓ | ✗ |
| `services` | ✓ | ✓ | ✓ | ✓ |
| `service_requests` | ✓ | ✓ | ✓ | ✓ |
| `transactions` | ✓ | ✓ | ✓ | ✓ |
| `blog_posts` | ✓ | ✓ | ✓ | ✗ |
| `ai_interaction_logs` | ✓* | ✓ | — | ✗ |
| `referrals` | ✓ | ✓ | ✓ | ✓ |
| `referral_rewards` | ✓ | ✓ | ✓ | ✓ |

*`ai_interaction_logs` : organization_id ajouté par `add_organization_id_to_tables` ; community_id potentiellement absent ou non documenté — à vérifier avant migration.

### Q4. Quelles tables ont community_id uniquement (pas d'organization_id) ?

| Table | community_id | organization_id | Observation |
|---|---|---|---|
| `loops` | ✓ | ✗ | Loop ≠ Tenant. Pas de migration prévue pour le moment. |

`loops` référence `community_id` via FK vers `communities`. Il n'a pas de `BelongsToTenantScope`. Loop est un concept interne à une Organization, pas un tenant. La colonne `community_id` ici est une FK de parenté, pas un filtre tenant.

### Q5. Quelles tables n'ont pas de scope tenant direct ?

| Table | Isolation tenant | Mécanisme de compensation |
|---|---|---|
| `users` | ✗ scope global | Filtrage manuel par `community_id` dans les controllers et guards |
| `blog_posts` | ✗ scope global | Filtrage par `community_id` dans `BlogController` |
| `reviews` | ✗ scope, ✗ colonne | Après T127 : filtrage via `whereHas('transaction', community_id)` |
| `loops` | ✗ scope | Accès par appartenance de membre, pas par tenant global |
| `ai_interaction_logs` | ✗ scope | Usage admin uniquement, pas exposé aux routes publiques |

### Q6. Que se passe-t-il si community_id != organization_id ?

Scénario A — `community_id = OrgA`, `organization_id = OrgB` :
- Service **visible** dans le listing OrgA (scope filtre `community_id = OrgA`) ✓
- Policy **bloque** update/delete pour un user OrgA (`org_id = OrgB ≠ OrgA`) ✗
- Résultat : service présent dans le listing mais impossible à modifier → incohérence UX, non exploitable directement

Scénario B — `community_id = OrgB`, `organization_id = OrgA` :
- Service **invisible** dans le listing OrgA (scope filtre `community_id = OrgB`) ✗
- Policy **autoriserait** update/delete pour un user OrgA (`org_id = OrgA`) ✓
- Résultat : service inaccessible par le listing normal mais policy permissive → non exploitable via les listings normaux ; risque si accès direct par ID

Ces deux scénarios sont documentés et couverts par `T126DesyncCommunityOrganizationIdTest` (8 tests verts).

### Q7. Quels tests protègent déjà ce comportement ?

| Fichier | Couverture |
|---|---|
| `tests/Feature/BelongsToTenantScopeTest.php` | Scope isolation, fail-closed, fallback community |
| `tests/Feature/HasOrganizationIdTest.php` | Synchronisation community_id / organization_id à la création et update |
| `tests/Feature/T126DesyncCommunityOrganizationIdTest.php` | 8 tests — divergence scope/policy, scénarios A et B, NULL, sync Eloquent |
| `tests/Feature/T0754DashboardMembersExchangesTenantSafetyTest.php` | Multi-org listing isolation |
| `tests/Feature/T0757ProfileOrganizationScopingTest.php` | Accès profil cross-org |
| `tests/Feature/Livewire/T126ExplorerTenantScopingTest.php` | Explorer isolation + tampering (corrigé T127) |
| `tests/Feature/T126ProfileReviewsTenantScopingTest.php` | Reviews cross-org (corrigé T127) |
| `tests/Feature/Policies/*PolicyTest.php` | Policies par modèle |

### Q8. Quels tests manquent encore ?

| Priorité | Test manquant | Justification |
|---|---|---|
| P1 | Transaction POST avec service d'une autre org | `TransactionController` fait `withoutGlobalScope` puis valide `community_id` — non testé cross-org |
| P1 | Loop access control | Loop a `community_id` FK mais pas de scope global — accès indirect non testé |
| P2 | BlogPost cross-org create/edit | BlogPost a HasOrganizationId mais pas de scope global — policy seule protège |
| P2 | Allowlist `withoutGlobalScope` | Pas de test d'inventaire automatique pour détecter un nouveau bypass |
| P2 | `ai_interaction_logs` présence community_id | À confirmer avant migration |

### Q9. Quelle serait la séquence sûre pour basculer vers organization_id ?

Voir section 4 — Roadmap migration sûre.

### Q10. Quels fichiers ne doivent surtout pas être modifiés par search/replace global ?

| Fichier | Raison |
|---|---|
| `app/Models/Scopes/BelongsToTenantScope.php` | Colonne pivot — changer `community_id` → `organization_id` sans backfill complet casserait l'isolation sur données historiques |
| `app/Http/Middleware/ResolveCommunity.php` | Bind `current_community` + `current_organization` — supprimer casserait la résolution tenant legacy |
| `database/migrations/*.php` | Migrations déjà exécutées en production — ne jamais modifier |
| `app/Models/Traits/HasOrganizationId.php` | Logique de synchronisation — changer l'ordre de priorité casserait les données nouvelles |
| `app/Support/Tenancy/CurrentOrganization.php` | Fallback `current_community` nécessaire pendant la migration |

---

## 3. Inventaire withoutGlobalScope (allowlist)

| Surface | Fichier | Classification | Risque | État |
|---|---|---|---|---|
| Explorer services | `Explorer.php:134` | Compat legacy — re-filtre serveur via `$communityId` | Corrigé T127 | ✓ SAFE |
| Explorer requests | `Explorer.php:190` | Compat legacy — re-filtre serveur via `$communityId` | Corrigé T127 | ✓ SAFE |
| Transaction service lookup | `TransactionController.php:38` | Bypass ciblé — lookup cross-org puis validation community_id | P1 résiduel | ⚠ À tester |
| Transaction request lookup | `TransactionController.php:48` | Idem | P1 résiduel | ⚠ À tester |
| Admin referrals (global) | `AdminReferralController.php:16-48` | Légitime plateforme-admin — vue globale intentionnelle | P2 conditionnel | ✓ ACCEPTABLE |
| Home members/services | `HomeController.php:46-49` | Compat legacy — re-filtre explicit `community_id` | P1 | ⚠ Dette legacy |
| Home exchanges | `HomeController.php:76` | Compat legacy | P1 | ⚠ Dette legacy |
| DemoSeeder | `database/seeders/DemoSeeder.php` | Hors runtime utilisateur | N/A | ✓ OK |
| Tests | `tests/Feature/*` | Légitimes — usage intentionnel pour valider les scopes | N/A | ✓ OK |

---

## 4. Roadmap migration sûre vers organization_id

Cette roadmap est **proposée** — chaque étape doit être validée par COCKPIT avant exécution.

### Étape 0 — Socle actuel (terminé)
- [x] `HasOrganizationId` synchronise les deux colonnes à la création/update Eloquent
- [x] `LegacyDataOrganizationSeeder` backfille les NULL sur 7 tables
- [x] Tests de désync T126 documentent le comportement actuel (8 tests verts)
- [x] T127 a corrigé Explorer et Profile reviews

### Étape 1 — Couverture tests (prochaine priorité)
- [ ] Ajouter test `TransactionController` POST cross-org (service/request d'une autre org doit rejeter)
- [ ] Vérifier que `LegacysDataOrganizationSeeder` a bien backfillé toutes les tables en production
- [ ] Ajouter un test de garde vérifiant l'absence de désync résiduelle (community_id ≠ organization_id) après backfill

### Étape 2 — Clarifier les tables sans organization_id
- [ ] Décider si `loops` doit avoir un `organization_id` (migration + HasOrganizationId)
- [ ] Confirmer la structure de `ai_interaction_logs` (community_id présente ?)
- [ ] Documenter `reviews` : pas de colonne tenant — isolation via transaction parente (validé T127)

### Étape 3 — Nettoyer les withoutGlobalScope legacy
- [ ] `HomeController.php` : remplacer `withoutGlobalScopes()` + re-filtre `community_id` par scope direct Organization-scopé
- [ ] `TransactionController.php` : tester + valider le bypass ciblé, documenter ou remplacer
- [ ] Explorer.php (corrigé T127) : à terme supprimer `withoutGlobalScopes()` et utiliser `BelongsToTenantScope`

### Étape 4 — Basculer BelongsToTenantScope (opération risquée)
Prérequis stricts avant cette étape :
1. Toutes les tables scoped ont `community_id = organization_id` sur 100% des lignes
2. Tests de désync verts
3. `withoutGlobalScope` allowlist réduite et documentée
4. Migration de données vérifiée en staging puis production

Opération :
```php
// BelongsToTenantScope.php — remplacer community_id par organization_id
$builder->where($model->getTable().'.organization_id', $organization->id);
```

### Étape 5 — Dépréciation community_id (long terme)
- Supprimer `HasOrganizationId` (sync inutile si une seule colonne)
- Supprimer le fallback `current_community` dans `CurrentOrganization`
- Retirer `ResolveCommunity` middleware
- Migration de suppression de colonne (une table à la fois, avec vérification)

---

## 5. Stratégie dual-write pour la période transitoire

Pendant la période où les deux colonnes coexistent, les règles opérationnelles sont :

1. **Toute création/update Eloquent** doit passer par un modèle avec `HasOrganizationId` → les deux colonnes sont synchronisées automatiquement.
2. **Toute insertion directe `DB::table()`** doit explicitement écrire les deux colonnes.
3. **Toute importation de données** (prod-mirror, seeders, imports) doit passer par `LegacyDataOrganizationSeeder` ou une étape de backfill équivalente.
4. **Tout nouveau modèle** avec un besoin de scoping tenant doit embarquer à la fois `HasOrganizationId` et `BelongsToTenantScope`.
5. **Aucun nouveau concept `community_id`** ne doit être introduit — utiliser `organization_id` pour toute nouvelle logique.

---

## 6. Risques confirmés et statut

| Risque | Priorité | Statut | Référence |
|---|---|---|---|
| Explorer communityId tampering | P0 | **CORRIGÉ T127** | T126ExplorerTenantScopingTest |
| Profile reviews cross-org | P1 | **CORRIGÉ T127** | T126ProfileReviewsTenantScopingTest |
| Désync community_id != organization_id | P0 documenté | **NON EXPLOITABLE** via listings normaux (données légitimes synchronisées par HasOrganizationId) | T126DesyncCommunityOrganizationIdTest |
| Transaction POST cross-org | P1 résiduel | Non testé | À couvrir étape 1 |
| HomeController withoutGlobalScopes legacy | P1 dette | Non corrigé | Étape 3 |
| Loop sans organization_id | P2 | Non décidé | Étape 2 |

---

## 7. Ce qu'il ne faut pas faire pendant la période dual-write

- Ne pas modifier `BelongsToTenantScope` pour basculer vers `organization_id` sans les prérequis de l'étape 4.
- Ne pas faire de search/replace global `community_id` → `organization_id` dans le code.
- Ne pas supprimer le fallback `current_community` dans `CurrentOrganization` sans supprimer aussi tous les `ResolveCommunity` usages.
- Ne pas introduire de nouveau vocabulaire `Community` dans la logique métier.
- Ne pas traiter `Loop` ou `Partner` comme tenant.
- Ne pas bypasser `HasOrganizationId` en utilisant `DB::table()` sans écrire les deux colonnes.
- Ne pas créer de modèle scoped sans `HasOrganizationId`.

---

## 8. Tests de garde ajoutés dans T128

Aucun test de garde supplémentaire n'a été nécessaire pour cette tâche.

Les tests existants couvrent déjà le comportement documenté :
- `T126DesyncCommunityOrganizationIdTest` (8 tests) — désync scope/policy
- `BelongsToTenantScopeTest` — comportement du scope
- `HasOrganizationIdTest` — synchronisation des colonnes

Les tests manquants identifiés (Transaction POST cross-org, Loop access, allowlist withoutGlobalScope) relèvent de tâches futures distinctes (T129+).

---

## 9. Go / No-Go T129 — withoutGlobalScope Allowlist & Guard Tests

### Verdict : **GO**

### Justification

T128 confirme que la stratégie dual-write est suffisamment claire pour encadrer les bypass `withoutGlobalScope` restants sans patch runtime.

**Réponses aux questions de décision :**

| Question | Réponse |
|---|---|
| Quels `withoutGlobalScope` restent dans le runtime ? | TransactionController (2), AdminReferralController (7 occurrences), HomeController (3) — Explorer corrigé T127 |
| Lesquels sont légitimes ? | AdminReferralController (plateforme-admin global intentionnel) |
| Lesquels sont suspects ? | HomeController (compat legacy avec re-filtre explicite — acceptable mais dette) |
| Lesquels sont dangereux ? | TransactionController (bypass avant validation community_id — non testé cross-org, P1 résiduel) |
| Lesquels doivent être couverts par tests ? | TransactionController — test POST cross-org manquant |
| Peut-on créer une allowlist sans modifier le runtime ? | Oui — documentation + test de garde d'inventaire uniquement |
| Y a-t-il un P0 qui impose un patch avant T129 ? | Non — aucun P0 runtime immédiat identifié |
| Recommandation | **GO T129** — scope : allowlist documentée + test garde TransactionController + inventaire |

### Conditions GO vérifiées

- [x] T127 mergée sur develop (96c2b6d)
- [x] T128 terminée, aucun bug P0 runtime
- [x] develop propre et poussé
- [x] bypasss classables proprement (admin légitime / legacy dette / suspect non testé)
- [x] allowlist apporte sécurité réelle sans refactor
- [x] tests de garde ajoutables sans modifier BelongsToTenantScope
- [x] scope limité documentation + tests

### Conditions NO-GO

- [ ] aucune condition NO-GO déclenchée

### Scope proposé pour T129

```text
TASK-129 — withoutGlobalScope Allowlist & Guard Tests

1. Créer docs/architecture/withoutGlobalScope-allowlist.md :
   - classer chaque bypass : admin / legacy / tests / risque
   - documenter la justification de chaque bypass
   - définir les critères d'ajout de nouveaux bypass

2. Ajouter test de garde :
   - vérifier que TransactionController rejette un POST avec service/request d'une autre org
   - ne pas tester AdminReferralController (plateforme-admin intentionnel)

3. Optionnel : test d'inventaire des bypass (rg withoutGlobalScope — échoue si nouveau bypass hors allowlist)
   NB: ce type de test est fragile — à valider COCKPIT avant implémentation

4. Ne pas modifier le runtime
5. Ne pas modifier BelongsToTenantScope
6. Ne pas supprimer les withoutGlobalScope existants
```

---

## 10. Sources consultées

- `docs/audits/T124-tenant-scope-residual-risk-audit.md`
- `TODO/TASK-126-t125-tenant-scope-p0-regression-tests.md`
- `TODO/TASK-127-t127-tenant-scope-p0-regression-fixes.md`
- `app/Models/Scopes/BelongsToTenantScope.php`
- `app/Models/Traits/HasOrganizationId.php`
- `app/Support/Tenancy/CurrentOrganization.php`
- `app/Policies/ServicePolicy.php`, `BlogPostPolicy.php`, `TransactionPolicy.php`, `ReviewPolicy.php`, `ServiceRequestPolicy.php`, `MessagePolicy.php`
- `app/Models/*.php` (Service, ServiceRequest, Transaction, BlogPost, Referral, ReferralReward, Loop, Review, User)
- `database/migrations/2026_05_12_101622_add_organization_id_to_tables.php`
- `database/seeders/DemoSeeder.php`, `LegacyDataOrganizationSeeder.php`
- `app/Http/Controllers/TransactionController.php`, `HomeController.php`, `Admin/AdminReferralController.php`
- `tests/Feature/T126DesyncCommunityOrganizationIdTest.php`
