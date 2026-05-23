# T124 - Tenant Scope Residual Risk Audit

Date: 2026-05-23 Europe/Paris  
Branche: `TASK-124-t124-tenant-scope-residual-risk-audit`  
Mode: audit read-only, sans patch runtime

## 1. Résumé exécutif

TASK-123 a réduit le risque immédiat sur `ProfileController` en empêchant l'accès public cross-organization au profil. L'audit résiduel TASK-124 confirme que les surfaces métier publiques sont majoritairement Organization-scopées, mais que plusieurs zones restent fragiles parce que le runtime mélange encore une source de vérité conceptuelle `Organization = Tenant` avec des colonnes legacy `community_id`.

Le risque principal n'est pas un défaut systémique global. Il est concentré sur les contournements manuels de `BelongsToTenantScope`, les propriétés Livewire publiques utilisées comme filtres tenant, les listes publiques composées, et les cas de désynchronisation `community_id != organization_id`.

## 2. Verdict global

Verdict: **acceptable pour audit, non prêt pour durcissement global sans arbitrage**.

- Pas de correction runtime recommandée dans TASK-124.
- Pas de migration brutale `community_id` vers `organization_id`.
- Pas de suppression massive de `withoutGlobalScope`.
- Priorité suivante: décider une petite série de tests de non-régression P0 avant tout refactor tenant.

## 3. Tableau `withoutGlobalScope`

| Surface | Fichier | Classification | Risque | Commentaire |
| --- | --- | --- | --- | --- |
| Explorer services | `app/Livewire/Explorer.php:131` | Risque réel | P0/P1 | `withoutGlobalScopes()` puis filtre sur `$this->communityId`. La propriété est publique Livewire et doit être considérée comme surface de tampering tant qu'elle n'est pas verrouillée ou recomputée côté serveur. |
| Explorer requests | `app/Livewire/Explorer.php:187` | Risque réel | P0/P1 | Même risque que services. Public ne veut pas dire global: `/explorer` doit rester Organization-scopé. |
| Transaction service lookup | `app/Http/Controllers/TransactionController.php:38` | Suspect | P1 | Bypass ciblé du scope puis validation `community_id`. Pas de fuite évidente, mais chargement cross-tenant avant rejet. |
| Transaction request lookup | `app/Http/Controllers/TransactionController.php:48` | Suspect | P1 | Même pattern que service lookup. |
| Members counters/services | `app/Http/Controllers/HomeController.php:46-49` | Compat legacy / dette future | P1 | Re-filtre par `community_id`, mais `withoutGlobalScopes()` est large. |
| Exchanges | `app/Http/Controllers/HomeController.php:76` | Compat legacy / dette future | P1 | Re-filtre par `community_id`. À garder sous tests de non-régression. |
| Admin referrals | `app/Http/Controllers/Admin/AdminReferralController.php:16-48` | Admin légitime sous hypothèse platform-admin | P2 | Correct si `admin` signifie administrateur plateforme. Devient risqué si un admin organization-scoped est introduit. |
| Seeder demo transactions | `database/seeders/DemoSeeder.php:382,386` | Compat legacy | P2 | Usage hors runtime utilisateur. |
| Tests | `tests/Feature/*` | Légitime | N/A | Usages destinés à valider le bypass ou les scopes. |

## 4. Tableau routes publiques

| Route | Résolution Organization | Filtrage | Risque |
| --- | --- | --- | --- |
| `/membres`, `/{community}/membres` | `ResolveUrlOrganization` global, `currentOrganization()` | `User::where('community_id', $communityId)` | Faible si tests T0754 conservés. |
| `/explorer`, `/{community}/explorer` | `ResolveUrlOrganization`, initialisation `communityId` depuis `currentOrganization()` | Livewire filtre `community_id` après `withoutGlobalScopes()` | Risque réel: propriété Livewire publique à tester/verrouiller ultérieurement. |
| `/services/{service}`, `/{community}/services/{service}` | Route publique, org résolue | Guard explicite `community_id !== currentOrganization()->id` | Faible. |
| `/blog` | Org résolue | Index/categories/tags filtrés par `community_id` | Faible après TASK-123. |
| `/blog/tag/{slug}` | Org résolue après tag lookup | Posts filtrés par `community_id` | P2: oracle/ambiguïté de tag global avant scope post. |
| `/blog/{post:slug}` | Route model binding avant guard | Guard explicite dans controller | P2: collision de slug possible, plutôt disponibilité/UX que fuite. |
| `/profile/{user}`, `/{community}/profile/{user}` | Org résolue | Guard utilisateur `community_id` | P1 résiduel sur `reviewsReceived()` non filtré directement par org. |

## 5. Tableau `community_id` vs `organization_id`

| Élément | Source actuelle | Observation | Risque |
| --- | --- | --- | --- |
| Runtime tenant | `app/Support/Tenancy/CurrentOrganization.php:25-30` | Préfère `current_organization`, fallback `current_community`. | Acceptable compat. |
| Scope global | `app/Models/Scopes/BelongsToTenantScope.php:17` | Filtre `community_id`, pas `organization_id`. | Dette centrale. |
| Synchronisation modèle | `app/Models/Traits/HasOrganizationId.php:23-42` | Synchronise les deux colonnes à la création/update. | Réduit le risque mais ne prouve pas les données historiques. |
| Policies | `app/Policies/*Policy.php` | Plusieurs policies raisonnent en `organization_id`. | Risque de désync si scope et policy divergent. |
| Middlewares legacy | `app/Http/Middleware/ResolveCommunity.php:22-23` | Bind `current_community` et `current_organization` au même objet. | Acceptable pendant migration. |
| Modèles scoped | `Service`, `ServiceRequest`, `Transaction`, `Referral`, `ReferralReward` | Scope appliqué localement. | Correct mais dépend de `community_id`. |
| Modèles non scoped | `BlogPost`, `User`, `Review` | Pas de `BelongsToTenantScope`. | Doit rester compensé par controllers/policies/tests. |

Pourquoi ne pas migrer brutalement maintenant: routes, middlewares, factories, scopes, policies et données historiques utilisent encore `Community`/`community_id` comme couche de compatibilité. Un remplacement large casserait probablement la résolution tenant, les route model bindings, la compatibilité SQLite/PostgreSQL et les tests existants.

Stratégie progressive proposée à arbitrer:

1. Documenter une allowlist des bypass `withoutGlobalScope`.
2. Ajouter des tests de désynchronisation volontaire `community_id != organization_id`.
3. Remplacer progressivement `withoutGlobalScopes()` par `withoutGlobalScope(BelongsToTenantScope::class)` quand le bypass est nécessaire.
4. Introduire un helper/scope tenant explicite qui vérifie les deux colonnes pendant la période dual-write.
5. Basculer `BelongsToTenantScope` vers `organization_id` seulement après couverture tests et migration de données.

## 6. Risques P0/P1/P2

| Priorité | Risque | Surface | Décision recommandée |
| --- | --- | --- | --- |
| P0 | Tampering Livewire tenant filter | `Explorer::$communityId`, `Explorer.php:131,187` | Ajouter tests avant patch; patch ultérieur possible avec propriété verrouillée ou calcul serveur. |
| P0 | Divergence source de vérité | Scope `community_id` vs policies `organization_id` | Ajouter matrice de tests désync avant toute migration. |
| P1 | Reviews cross-org sur profil | `ProfileController.php:30`, `Review` sans tenant scope | Tester qu'un profil org A n'affiche pas de review issue d'une transaction org B. |
| P1 | Bypass transaction avant validation | `TransactionController.php:38,48` | Tester POST avec service/request d'autre org: rejet sans création. |
| P1 | Listes publiques composées | `/membres`, `/echanges`, `/explorer` | Garder tests root + slug org. |
| P2 | Blog tag oracle | `BlogController.php:79-82` | À traiter si collisions/tags globaux deviennent sensibles. |
| P2 | Admin referrals global | `AdminReferralController.php` | À réévaluer si admin devient organization-scoped. |
| P2 | Documentation draft root-domain | `docs/architecture/01-ROOT_DOMAIN_TENANT_RESOLUTION.md` | Ne pas l'utiliser comme source principale sans lire les notes de statut. |

## 7. Tests existants

- `tests/Feature/BelongsToTenantScopeTest.php`: current organization, fallback current community, fail-closed sans tenant, bypass explicite.
- `tests/Feature/HasOrganizationIdTest.php`: synchronisation `organization_id` / `community_id`.
- `tests/Feature/T0754DashboardMembersExchangesTenantSafetyTest.php`: dashboard, membres, échanges cross-organization.
- `tests/Feature/T0755ServicesRequestsTenantSafetyTest.php`: hidden-field tampering et show cross-org pour services/requests.
- `tests/Feature/T0756BlogOrganizationScopingTest.php`: blog public et authoring Organization-scopés.
- `tests/Feature/T0757ProfileOrganizationScopingTest.php`: accès profil public cross-org.
- `tests/Feature/Livewire/ExplorerTest.php`: rendu et filtres Explorer, sans matrice multi-org complète.
- `tests/Feature/Policies/*PolicyTest.php`: policies BlogPost, Service, ServiceRequest, Transaction, Review.
- `tests/Feature/Api/ApiTenantScopingTest.php`: couverture partielle API tenant-scope.
- `tests/e2e/community-transactions/workflows/QA-04-reviews.spec.js`: reviews happy path, mais assertions cross-org faibles.

## 8. Tests manquants

| Priorité | Test proposé | Objectif |
| --- | --- | --- |
| P0 | `ExplorerTenantScopingTest` | Vérifier `/explorer` et `/{community}/explorer` avec services/requests de deux orgs, recherche, catégories, tri, onglets. |
| P0 | Désync policies/scope | Créer données `community_id != organization_id` pour Service, ServiceRequest, Transaction, BlogPost, Review. |
| P0 | Profile reviews tenant | Vérifier que `reviewsReceived()` ne rend pas de review cross-org sur profil public. |
| P1 | Transaction POST cross-org | ID service/request d'une autre org doit rejeter sans créer de transaction. |
| P1 | Route matrix public root/slug | `/membres`, `/echanges`, `/services`, `/requests`, `/profile`, `/blog`, `/explorer`. |
| P1 | API public show cross-org | `/api/services/{id}`, `/api/requests/{id}`, `/api/users/{id}` selon org résolue/auth. |
| P2 | Inventaire `withoutGlobalScope` | Échec si nouveau bypass hors allowlist documentée. |
| P2 | E2E reviews profil | Assertions bloquantes d'absence de contenu cross-community. |

## 9. Tâches futures proposées, mais non validées

Ces éléments sont des propositions à arbitrer, pas des TASK décidées:

- Proposition A: ajouter une suite de tests P0 sur Explorer, Profile reviews et désync `community_id` / `organization_id`.
- Proposition B: créer une allowlist documentée des usages `withoutGlobalScope`, avec classification admin/legacy/suspect/risque réel.
- Proposition C: préparer un helper tenant explicite pendant la période dual-write.
- Proposition D: clarifier le statut de `docs/architecture/01-ROOT_DOMAIN_TENANT_RESOLUTION.md` si ce document continue à être consulté par les agents.

## 10. Ce qu'il ne faut pas faire maintenant

- Ne pas migrer globalement `community_id` vers `organization_id`.
- Ne pas renommer massivement `Community` en `Organization`.
- Ne pas supprimer massivement `withoutGlobalScope`.
- Ne pas modifier `BelongsToTenantScope` sans tests de désync et migration de données.
- Ne pas traiter `Loop` ou `Partner` comme tenant.
- Ne pas considérer les routes publiques comme globales.
- Ne pas corriger SQLite batch ou seeds dans cette tâche.
- Ne pas modifier de runtime applicatif dans TASK-124.

## 11. Synthèse CAO

| Agent | Session / terminal | Statut | Résultat |
| --- | --- | --- | --- |
| Agent 1 - `withoutGlobalScope` | `cao-t124-agent1-withoutglobalscope` / `f36d73f8` | Réussi | Classification des bypass et identification Explorer/Transaction/Home/Admin. |
| Agent 2 - routes publiques | `cao-t124-agent2-public-routes` / `0605570f` | Réussi | Confirme public Organization-scopé, risques Explorer/Profile/Blog tag. |
| Agent 3 - `BelongsToTenantScope` source-of-truth | premier essai `bca22b09`, relance `0e27034c` | Réussi avec fallback log | Premier lancement timeout/stale tmux; résultat récupéré dans le log terminal. |
| Agent 4 - tests gap | `cao-t124-agent4-tests-gap` / `73ada517` | Réussi | Matrice tests P0/P1/P2. |
| Agent 5 - documentation consistency | `cao-t124-agent5-docs-consistency` / `1412e79d` | Réussi | Doctrine cohérente; ambiguïtés documentaires limitées et actionnables. |

Notes CAO:

- `cao-server` doit être démarré avec `TERM=xterm-256color`, sinon tmux peut hériter de `TERM=dumb` et provoquer `open terminal failed: terminal does not support clear`.
- L'erreur `can't find window: audit-scope-policies-4f7a` sur `bca22b09` est classée comme référence stale après timeout de lancement, pas comme panne serveur actuelle.
- Les lancements CAO ont été effectués séquentiellement après ce constat.

## 12. Commandes read-only exécutées

- `curl -sS http://127.0.0.1:9889/health`
- `cao session list`
- `cao launch --agents audit-scope-policies --session-name t124-agent1-withoutglobalscope --headless --async --auto-approve --provider codex ...`
- `cao launch --agents audit-public-surfaces --session-name t124-agent2-public-routes --headless --async --auto-approve --provider codex ...`
- `cao launch --agents audit-scope-policies --session-name t124-agent3-belongs-scope --headless --async --auto-approve --provider codex ...`
- `cao launch --agents audit-scope-policies --session-name t124-agent4-tests-gap --headless --async --auto-approve --provider codex ...`
- `cao launch --agents audit-doctrine --session-name t124-agent5-docs-consistency --headless --async --auto-approve --provider codex ...`
- `curl -sS http://127.0.0.1:9889/terminals/<id>/output?mode=full`
- `cao shutdown --session <session>`
- `rg -n "withoutGlobalScope|withoutGlobalScopes" app database tests`
- `rg -n "Route::|membres|explorer|services|blog|profile|ResolveUrlOrganization" routes/web.php bootstrap/app.php`
- `rg -n "class BelongsToTenantScope|current_organization|current_community|community_id|organization_id" app/Models/Scopes/BelongsToTenantScope.php app/Support/Tenancy/CurrentOrganization.php app/Models/Traits/HasOrganizationId.php app/Http/Middleware/ResolveCommunity.php app/Http/Middleware/ResolveOrganization.php`
- `rg -n "reviewsReceived|currentOrganization|community_id|organization_id" app/Http/Controllers/ProfileController.php app/Http/Controllers/BlogController.php app/Livewire/Explorer.php app/Http/Controllers/HomeController.php app/Http/Controllers/TransactionController.php app/Http/Controllers/ServiceController.php`
- `rg -n "BelongsToTenantScope|HasOrganizationId|booted|community_id|organization_id" app/Models/*.php`
- `rg -n "tenant|organization|community|scope|Explorer|Profile|Review|withoutGlobalScope|BelongsToTenantScope|cross-org|T075" tests/Feature tests/e2e`
