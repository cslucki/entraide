---
task_id: TASK-071
title: organization-aware-routing-strategy

status: MERGED

owner: OPENCODE

contributors:
  - OPENCODE

branch: TASK-071-organization-aware-routing-strategy

priority: MEDIUM

created_at: 2026-05-13 07:49:22 Europe/Paris
updated_at: 2026-05-13 09:30:00 Europe/Paris

labels:
  - routing
  - organization
  - strategy
  - compatibility

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: false

pr:
  status: MERGED
  url: https://github.com/cslucki/entraide/pull/28
  url: null
---

# Objective

Préparer la future stratégie de routing organization-native.

Cartographier le routing actuel Community, identifier les impacts runtime,
préparer une stratégie additive organization-aware, stabiliser les helpers,
et poser le groundwork pour la migration progressive future.

NE PAS migrer les routes. NE PAS casser les routes actuelles.

---

# Audit — Routing Architecture Current State

## 1. URL Scheme Patterns (Two coexist)

### Global routes (non-scoped)
| URI | Named Route | Usage |
|-----|-------------|-------|
| `/` | `home` | Landing page |
| `/login`, `/register`, `/forgot-password`, `/reset-password/*` | — | Breeze auth |
| `/dashboard` | `dashboard` | User dashboard |
| `/services/{service}` | `services.show` | Service show |
| `/services/{service}/edit` | `services.edit` | Service edit |
| `/requests/{request}` | `requests.show` | Request show |
| `/messages` | `messages.index` | Messages list |
| `/messages/{transaction}` | `messages.show` | Message thread |
| `/profile/{user}` | `profile.show` | Profile show |
| `/blog/*` | `blog.*` | Blog system |
| `/admin/*` | `admin.*` | Admin panel |
| `/explorer` | `explorer` | Explorer page |
| `/search` | `search` | Search |
| `/boucles/creer` | `boucles.request.*` | Community creation |
| `/sitemap.xml` | `sitemap` | SEO |

### Community-scoped routes: `/{community}/...`
Prefix: `/{community}` | Middleware: `['web', 'community']` | Name: `community.*`
Constraint: `$communityConstraint` (negative lookahead for reserved words)

~50 sub-routes: home, login, register, dashboard, services.*, requests.*, transactions.*, messages.*, profile.*, favorites.*, reports.*, members, echanges, explorer

### Organization route preparation (currently dormant)
```php
$organizationConstraint = $communityConstraint;
// Future: Route::prefix('/org/{organization}')...
//        ->middleware(['web', 'organization'])
//        ->name('organization.')...
```

File: `routes/web.php:228-230`

## 2. Middleware Resolution

### ResolveCommunity (`app/Http/Middleware/ResolveCommunity.php`)
- Reads `$request->route('community') ?? $request->route('organization')`
- Resolves via `Community::findBySlug($slug)` — manual, no model binding
- Binds both `current_community` AND `current_organization` as app singletons
- Shares `currentCommunity` AND `currentOrganization` to all views
- 404 on unknown/inactive

### ResolveOrganization (`extends ResolveCommunity`)
- Semantic alias during Community → Organization migration
- Registered as `'organization'` in `bootstrap/app.php`
- Same behavior as ResolveCommunity

### Middleware Aliases (`bootstrap/app.php`)
```php
'community' => ResolveCommunity::class,
'organization' => ResolveOrganization::class,
```

## 3. Community Model — Route Key
- `Community::getRouteKeyName()` — NOT overridden (default: `'id'`)
- But routes pass SLUG — resolved manually via `findBySlug()` in middleware
- Important: implicit binding uses `id`, explicit middleware uses `slug`

### Organization Model
- `Organization::getRouteKeyName()` returns `'slug'` — ready for route model binding
- Shares `communities` table (extends Community)

## 4. URL Generation Patterns

### Named routes with `'community'` param (11 call sites)
| Route | Blade/PHP | Location |
|-------|-----------|----------|
| `community.home` | `route('community.home', ['community' => $slug])` | Auth controllers (login, register redirects) |
| `community.dashboard` | `route('community.dashboard', ['community' => $slug])` | community/landing.blade.php |
| `community.explorer` | `route('community.explorer', ['community' => $slug])` ×2 | community/landing.blade.php |
| `community.register` | `route('community.register', ['community' => $slug])` ×2 | community/landing.blade.php |
| `community.login` | `route('community.login', ['community' => $slug])` | community/landing.blade.php |
| `community.login` redirect | `redirect()->route('community.login', ['community' => $slug])` | CommunityLandingController |
| `community.services.show` | `route('community.services.show', ['community' => $slug, 'service' => $s])` | community/landing.blade.php |

### Hardcoded URLs (bypass route system)
| File | Line | Code | Risk |
|------|------|------|------|
| `resources/views/boucles/index.blade.php` | 23 | `<a href="/{{ $community->slug }}/"` | MEDIUM — breaks if URL scheme changes |
| `resources/views/admin/communities/index.blade.php` | 29 | `url('/' . $c->slug)` | MEDIUM — breaks if URL scheme changes |

### Unused patterns
- `to_route()` — 0 calls (good: no migration needed)
- `organization.*` named routes — 0 calls
- Route model binding for Community/Organization — 0 calls
- Livewire `redirectRoute()` — 0 calls

## 5. Auth Routing Flow

### Login flow (AuthenticatedSessionController)
1. User logs in via POST `/{community}/login` or global `/login`
2. If `$user->community_id` exists AND community is active:
   → `redirect()->intended(route('community.home', ['community' => $community->slug]))`
3. Fallback: `redirect()->intended(route('dashboard'))`

### Register flow (RegisteredUserController)
1. User registers via POST `/{community}/register` or global `/register`
2. Stores `community_id` from `currentOrganization()?->id`
3. If resolved: → `redirect()->intended(route('community.home', ['community' => $slug]))`
4. Fallback: `redirect()->intended(route('dashboard'))`

### Community Landing (CommunityLandingController)
1. __invoke(string $community) — receives slug as string param
2. Resolves via `Community::findBySlug($community)`
3. If private → redirect unauthenticated to `community.login`

### DashboardController
- Serves both `/dashboard` and `/{community}/dashboard`
- NO tenant scoping in queries (potential cross-org exposure)

## 6. Playwright URL Patterns

All Playwright community tests construct URLs via path concatenation:
```js
`/${slug}/dashboard`, `/${slug}/services/${id}`, etc.
```

Defined in:
- `tests/e2e/community-transactions/helpers/config.js` — `COMMUNITY_ROUTES` object
- `tests/e2e/community-transactions/helpers/community.js` — goTo*() helper functions
- No named routes used anywhere in Playwright

**Risk**: When transitioning to `/org/{organization}`, ALL Playwright URL-building helpers must be updated.

## 7. SEO Impact

- `/sitemap.xml` — generates URLs for `/services/{uuid}`, `/profile/{uuid}` (global, no community prefix)
- No per-community sitemaps exist
- No canonical URL management for dual-routing
- `$communityConstraint` negative lookahead prevents slug collisions

---

# Impacts Identified

## Critical Points

1. **Hardcoded Blade URLs** (×2) — bypass route system, must be fixed before any migration
2. **Auth redirects** (login/register) — hardcode `'community' => $slug` param, need abstraction
3. **Playwright URL construction** — 14 functions in config.js/community.js, path-based not route-based
4. **Community model route key** — uses `id` by default, middleware resolves via `slug` manually (fragile coupling)
5. **Dashboard data scoping** — no tenant filter (potential cross-org leak on global `/dashboard`)
6. **Global routes** (`/services/{service}`, `/profile/{user}`, `/messages/{transaction}`) — no tenant scope

## High-Risk Areas (for future migration)

1. SEO regression — search engines indexed `/{slug}/...` URLs
2. Playwright test regressions — all 16 e2e test files construct community URLs
3. Auth redirects — 2 controllers hardcode community route param
4. 11 `route('community.*')` call sites across Blade/PHP

## Already Mitigated (by TASK-069)

- ✅ `ResolveOrganization` middleware exists
- ✅ `organization` alias registered
- ✅ `CurrentOrganization` helper with fallback
- ✅ `currentOrganization()` global function autoloaded
- ✅ `Organization` model extends Community
- ✅ `BelongsToTenantScope` uses CurrentOrganization

---

# Additive Groundwork (weak risk)

## 1. `organizationRoute()` helper — route() abstraction
Additive helper that accepts `'organization'` OR `'community'` param.
Currently transparent (maps both to `community.*` routes).
Future: swapping to `organization.*` routes is a single-config change.

## 2. Fix hardcoded Blade URLs (×2)
Replace `/<a href="/{{ $slug }}/"` and `url('/' . $slug)` with `community.home` named route.
These are genuine tech debt, not premature migration.

## 3. Routing strategy documentation
Create `ai/context/routing-strategy.md` documenting:
- Current architecture
- Dual routing future plan
- Migration checklist
- Playwright impact
- Helper abstraction contract

## 4. CommunityLandingController route parameter hinting
Add explicit route binding documentation/pattern for future model binding.

---

# Architecture Decisions

1. **Additive only** — no deleted code, no modified routes, no changed middleware
2. **`organizationRoute()` helper** — wraps `route()` with dual `organization`/`community` param. Currently: `community` → `community.home`. Future: config-switchable to `organization` → `organization.home`.
3. **Hardcoded URL fixes** — use `route('community.home', ['community' => $slug])` instead of `"/$slug/"`
4. **Documentation first** — strategy doc in `ai/context/routing-strategy.md` before any migration code
5. **No route migration yet** — consistent with TASK constraints

# Progress Log

## 2026-05-13 07:49:22 Europe/Paris

Task created.

## 2026-05-13 08:00:00 Europe/Paris

Audit complet terminé — 16 points inspectés :
1. Routes web.php (322 lignes) — cartographie exhaustive
2. Routes api.php (42 lignes) — pas de community scope
3. Routes auth.php (59 lignes) — importé avant {community}
4. ResolveCommunity middleware — lecture dual param {community}/{organization}
5. ResolveOrganization middleware — alias sémantique
6. bootstrap/app.php — aliases middleware
7. Modèles Organization + Community — route keys
8. URL generation — 11 route() calls, 2 hardcoded URLs
9. Auth controllers — login/register redirects
10. DashboardController — tenant scoping absent
11. Playwright tests — 16 files, path-based URL construction
12. Sitemap — global only
13. helpers.php — currentOrganization() existant
14. CurrentOrganization tenancy class
15. Tests — OrganizationRouteCompatibilityTest (9 tests)
16. Route model binding — non configuré

## 2026-05-13 08:02:00 Europe/Paris

Implémentation additive faible risque :
1. `organizationRoute()` helper ajouté dans `app/Support/helpers.php`
2. Hardcoded Blade URL boucles/index.blade.php → `community.home` named route
3. Hardcoded Blade URL admin/communities/index.blade.php → `community.home` named route
4. `ai/context/routing-strategy.md` créé (documentation stratégique complète)
5. Pint exécuté sur fichiers modifiés
6. Full test suite : 310 passed, 621 assertions

## 2026-05-13 08:05:00 Europe/Paris

Finalisation première passe :
- Audit routing complet ✅
- TASK update exhaustif ✅
- Tests verts ✅
- Aucune régression ✅
- Aucun breaking change ✅
- Prêt pour check-task.sh + finalize-task.sh

## 2026-05-13 08:30:00 Europe/Paris

SECOND PASS — Architecture Review (9 axes)

Documents relus :
- CLAUDE.md
- AGENTS.md
- ai/context/architecture.md
- ai/context/multi-tenant.md
- ai/context/routing-strategy.md
- TODO/TASK-071

Axes investigués via Laravel Boost + targeted task agents :
1. organizationRoute() helper — unused, abstraction intacte
2. Route model binding — asymétrie Community(id) vs Organization(slug)
3. Middleware consistency — dual binding OK, mais scope mort sur routes globales
4. Global routes tenant gaps — MAJEUR : SearchController, ServiceController.show, RequestController.show
5. Playwright URL patterns — 14 fonctions, regex fragile getCommunitySlug()
6. SEO / canonical strategy — sitemap global seulement, pas de canonical
7. Livewire/Alpine impacts — Explorer OK (manual scope), MessageThread zéro tenant awareness
8. Laravel internals — pas de URL::defaults(), notifications → routes globales
9. Future architecture risks — Route duplication, notification URLs, scope désactivé

# Handoffs

No handoff. OPENCODE termine cette tâche.

# Tests

- [x] feature tests — 310 passed, 621 assertions
- [ ] browser validation — non requis (aucune modif UI fonctionnelle)
- [ ] responsive validation — non requis
- [ ] console inspection — non requis
- [ ] tenant validation — non régressé (OrganizationRouteCompatibilityTest ✅)

---

# Test Results

Full suite : 310 passed, 621 assertions. Zero regression.

---

# Architecture Review — Second Pass

## 1. `organizationRoute()` Helper

**Findings :**
- Défini dans `app/Support/helpers.php:12-37` — wrapping `route()` avec mapping `organization` → `community`
- **ZÉRO appelant** dans tout le codebase (controllers, vues, Livewire, notifications)
- API : `organizationRoute('community.home', ['organization' => $slug])` — cohérent mais inutilisé
- Route cache : aucun impact (wrapper stateless, pas de state global)
- Dual-routing futur : extensible via config switch, mais pas encore testé

**Risques :**
- LOW : helper inutilisé = abstraction premature (dead code potentiel)
- LOW : si utilisé partout plus tard, migration massive des call sites `route()` → `organizationRoute()`

**Recommandation :** Laisser en l'état (additive, non destructive). 
Ne pas forcer l'usage tant que les routes `/org/{organization}` ne sont pas activées.

---

## 2. Route Model Binding

**Findings — Asymétrie critique :**

| Modèle | getRouteKeyName() | Résolution | Routes concernées |
|--------|------------------|------------|-------------------|
| `Community` | `id` (défaut) | UUID | Admin : `admin/communities/{community}` (4 routes) |
| `Organization` | `slug` (override) | Slug textuel | Aucune active (future `/org/{organization}`) |

**IMPACT :**
- HIGH : Si `Community::getRouteKeyName()` passe à `'slug'` → les 4 routes admin cassent (404 silencieux car UUID cherché dans colonne slug)
- MEDIUM : `Organization` a `getRouteKeyName()='slug'` mais AUCUNE route ne type-hinte `Organization $org` — ce code est dormant mais prêt
- LOW : Les routes `/{community}/*` contournent le binding via middleware `ResolveCommunity::findBySlug()` — safe
- LOW : `resolveChildRouteBinding()` — jamais override, jamais utilisé
- LOW : `scopeBindings()` — jamais utilisé

**Architecture note :**
Le routing community utilise un pattern hybride : middleware de résolution manuelle pour le slug public (`/{community}/...`) + implicit binding pour l'ID en admin (`/admin/communities/{id}`). C'est intentionnel et stable.

**Recommandation :**
- INTERDICTION de toucher à `Community::getRouteKeyName()` sans TASK dédiée
- `Organization::getRouteKeyName()='slug'` est correct pour la cible `/org/{organization}` future

---

## 3. Middleware Consistency

**Findings :**
- `ResolveCommunity` lit `$request->route('community') ?? $request->route('organization')` — dual param OK
- Bind les DEUX : `current_community` + `current_organization` sur la même instance Community
- `ResolveOrganization extends ResolveCommunity` — vide, alias sémantique pur
- Aliases `'community'` et `'organization'` enregistrés dans `bootstrap/app.php`

**PROBLÈME CRITIQUE :**
Le middleware `community`/`organization` n'est appliqué QUE sur le groupe `/{community}` :
```php
Route::prefix('/{community}')
    ->middleware(['web', 'community'])  // ← SEULEMENT ici
```
Sur TOUTES les routes GLOBALES (`/services`, `/dashboard`, `/search`, etc.),
`current_organization` et `current_community` ne sont JAMAIS bindés.
→ `BelongsToTenantScope` ne filtre RIEN sur les routes globales.

**IMPACT HIÉRARCHIQUE :**
```
Route globale (pas de /{community}/)
  ↓ ResolveCommunity NE TOURNE PAS
  ↓ current_organization = null
  ↓ CurrentOrganization::get() = null
  ↓ BelongsToTenantScope : PAS DE WHERE
  ↓ Service::all() retourne TOUS les services (toutes orgs)
```

Auth redirects : login/register → `route('community.home', ['community' => $slug])` — hardcodé community, pas de dual-route support.

Guest flows : `CommunityLandingController` → resolved slug manuellement, pas de middleware guest redirect.

**Recommandation :** Laisser le middleware tel quel (stable). Le problème des routes globales est structurel et nécessite une TASK dédiée (TASK-072+).

---

## 4. Global Routes — Tenant Isolation Gaps

### Analyse complète

| Route | Controller | Tenant Scope | Risque |
|-------|-----------|-------------|--------|
| `GET /search` | SearchController | **AUCUN** | **HIGH** — Cherche services/requests/users de TOUTES les communautés. Pas d'alternative community-scoped (search exclu du regex) |
| `GET /services/{service}` | ServiceController.show | **AUCUN** (scope inactif) | **HIGH** — `/services/{uuid}` expose les services actifs de TOUTES les orgs |
| `GET /requests/{request}` | RequestController.show | **AUCUN** | **HIGH** — Même problème, requêtes ouvertes visibles globalement |
| `GET /profile/{user}` | ProfileController.show | **AUCUN** | **HIGH** — Agrège les services/transactions de TOUTES les communautés pour un user |
| `POST /services` (global) | ServiceController.store | **AUCUN** | **HIGH** — `community_id` vient du request input, pas de la résolution tenant. Peut créer des services orphelins (null) |
| `POST /requests` (global) | RequestController.store | **AUCUN** | **HIGH** — Même risque d'orphelinat |
| `POST /favorites/{service}/toggle` | FavoriteController.toggle | **AUCUN** | **MEDIUM** — Service scope inactif, peut fav les services cross-org |
| `GET /messages` (global) | MessageController.index | **AUCUN** | **MEDIUM** — User-scoped mais cross-org aggregation |
| `GET /messages/{transaction}` | MessageController.show | **AUCUN** | **MEDIUM** — Policy-gated mais cross-org loading |
| `GET /transactions/*` (global) | TransactionController.* | **AUCUN** | **MEDIUM** — Cross-org by design (withoutGlobalScope explicite). Policies gate |
| `POST /reports/*` (global) | ReportController.* | **AUCUN** | **MEDIUM** — Cross-org reports possibles |
| `GET /dashboard` | DashboardController | **AUCUN** | **LOW** — User-scoped (buyer_id/seller_id) mais cross-org mix |
| `GET /explorer` | ExplorerController | **AUCUN** | **LOW** — Données taxonomiques uniquement |

### Root Cause

`BelongsToTenantScope` est un global scope Eloquent qui ne s'active que si `CurrentOrganization::get()` retourne non-null.
Or `CurrentOrganization::get()` ne retourne non-null que si `ResolveCommunity` middleware a bindé `current_organization`.
Et `ResolveCommunity` middleware ne tourne QUE sur `/{community}/...`.

**Conclusion :** Les 3 modèles avec `BelongsToTenantScope` (Service, ServiceRequest, Transaction) n'ont AUCUNE isolation tenant sur les routes globales.

### Controllers avec routes DUPLIQUÉES (global + community)

13 controllers ont leurs méthodes accessibles via DEUX URL schemes différents :
- DashboardController, ServiceController, RequestController, TransactionController, ReviewController,
  MessageController, ProfileController, FavoriteController, ReportController, ExplorerController

Les routes globales et community-prefixed appellent les MÊMES méthodes controller.
Les méthodes ne font PAS de distinction d'isolation selon le contexte d'appel.

### Notification Emails

Routes globales dans les emails :
- `route('messages.show', $transaction)` dans `new_message.blade.php` et `transaction_status.blade.php`
- `route('explorer')` dans `welcome.blade.php`
- AUCUN contexte community passé

**Recommandation :** TASK-072 dédiée au SearchController scoping. TASK-073 pour les URLs de notification.

---

## 5. Playwright Architecture

### URL Construction

**14 lambdas** dans `tests/e2e/community-transactions/helpers/config.js` :
```js
COMMUNITY_ROUTES = {
  dashboard: (slug) => `/${slug}/dashboard`,
  services: (slug) => `/${slug}/services`,
  // ... 12 autres
}
```

**9 goTo* fonctions** dans `tests/e2e/community-transactions/helpers/community.js` : 
- Toutes construisent `/${communitySlug}/path`

### Points Faibles

1. **`getCommunitySlug()`** (community.js:13-17) :
```js
const match = url.match(/\/([a-z0-9-]+)\//);
return match ? match[1] : 'default';
```
- **FRAGILE** : Suppose que le slug est TOUJOURS le premier segment de path
- Si l'URL devient `/org/{slug}/dashboard` → extrait `org` au lieu du slug
- Impact : TOUS les helpers goTo* qui utilisent le fallback implicite

2. **`goToProfile()`** (community.js:95) : Utilise `/profile/${userId}` — route globale, pas de contexte community

3. **Auth helpers** (`ai/playwright/helpers/auth.js`) : Routes `/login` et `/logout` globales — slug-agnostiques, les moins impactées

### Recommandation
- Phase 4 du migration blueprint : Playwright = 2eme grosse phase après activation /org/
- Centraliser la construction URL via `COMMUNITY_ROUTES` rend la migration plus facile
- `getCommunitySlug()` est LE point de fragilité max — devra être le premier fixé

---

## 6. SEO / Canonical Strategy

### État actuel
- Sitemap global : `/sitemap.xml` → génère `/services/{uuid}` et `/profile/{uuid}` (routes globales)
- Pas de sitemap per-community
- Pas de canonical URL management
- Pas de redirect strategy

### Risques futurs
- HIGH : Quand `/org/{organization}` sera activé, `/boucle-ma-commu/services/{uuid}` ET `/org/ma-commu/services/{uuid}` pointeront vers le même contenu → **duplicate content**
- HIGH : Les moteurs de recherche ont indexé `/{slug}/...` — migration vers `/org/{org}/...` = SEO disruption
- MEDIUM : Aucun `<link rel="canonical">` préparé pour la coexistence

### Recommandation
- Phase 5 du blueprint : SEO & Canonical — ne PAS traiter maintenant
- Ajouter canonical ET 301 redirect dans la même phase

---

## 7. Livewire / Alpine Impacts

### Explorer (`app/Livewire/Explorer.php`)

| Aspect | État | Risque |
|--------|------|--------|
| Tenant resolution | `currentOrganization()?->id` via mount() | OK — helper résout les deux bindings |
| Query scoping | `withoutGlobalScopes()` + `where('community_id')` | OK — pattern manuel intentionnel |
| Route links in view | `route('services.show')`, `route('requests.show')` | **MEDIUM** — routes GLOBALES, pas community-prefixed. Fonctionne car ces routes sont dupliquées, mais incohérent |
| URL state | `#[Url]` attributes on filters | OK — query string, pas de conflit |

### MessageThread (`app/Livewire/MessageThread.php`)

| Aspect | État | Risque |
|--------|------|--------|
| Tenant awareness | **ZÉRO** | Aucune propriété communityId, aucun appel currentOrganization() |
| Scoping | Aucun | Transaction loadée via route model binding |
| Route links in view | `route('transactions.approve')` etc — TOUTES globales | Fonctionne (routes dupliquées) mais zéro garde-fou tenant |
| Hydration | Dépend du parent controller `MessageController::show($transaction)` | Transaction loadée sans scope tenant |

**Finding critique :** MessageThread n'a AUCUNE conscience du tenant. Il opère sur le Transaction model via implicit binding — n'importe quelle transaction de n'importe quelle org peut être chargée si l'UUID est connu et la policy passe.

### Vues avec `$currentCommunity` / `$currentOrganization` :
5 vues utilisent `$currentCommunity ?? $currentOrganization` — pattern de fallback cohérent.

---

## 8. Laravel Internals

| Check | Résultat | Risque |
|-------|---------|--------|
| `URL::defaults()` | NON utilisé | LOW — pas de centralisation du slug |
| Signed URLs | 1 seule route (`verify-email`) | LOW — pas de signed pour les routes community |
| route cache | Non testé | LOW — routes statiques, cache compatible |
| Pagination links | 18 `->links()` — context-correct par URL | LOW — fonctionne car sur la même URL |
| Notification emails | `route('messages.show')` global | **MEDIUM** — pas de contexte community |
| `routeIs()` dans Blade | Navigation highlighting uniquement | LOW — patterns globaux (`explorer*`, `members*`) |
| `request()->route()` in app/ | Seulement dans ResolveCommunity | LOW — propre |
| Rate limiting | `throttle:10,1` sur transactions.store | OK — indépendant du routing |

---

## 9. Future Architecture Risks

### Ce qui sera DIFFICILE à migrer plus tard

1. **Routes globales → tenant-scoped** : 13 controllers avec routes dupliquées. Migrer vers /org/{org} nécessite de repenser si les routes globales doivent disparaître ou rester
2. **Notification emails** : `route('messages.show')` sans community → si on supprime les routes globales, les emails cassent
3. **Playwright regex** : `getCommunitySlug()` = fragilité max, devra être la première chose fixée
4. **SearchController** : Pas d'alternative community-scoped, le regex l'exclut. Nouvelle route nécessaire
5. **BelongsToTenantScope** : Global scope = silencieux. Les développeurs peuvent oublier qu'il ne s'applique pas sur routes globales

### Ce qui DEVRAIT être abstrait MAINTENANT (mais ne l'est pas)

1. **`organizationRoute()` helper** : Défini mais inutilisé. Devrait être utilisé pour les appels route() community-dépendants
2. **Notification URL context** : Les notifications devraient passer le community slug pour générer des URLs contextuelles
3. **SearchController scoping** : Devrait au minimum filtrer par current_organization quand disponible

### Ce qui DOIT attendre

1. **Activation `/org/{organization}`** : Nécessite canonical + redirect + Playwright = minimum 2 TASKs dédiées
2. **Suppression des doublons global/community** : Breaking change, nécessite coordination
3. **getRouteKeyName sur Community** : Bloqué par admin routes

### Ce qui serait DANGEREUX trop tôt

1. **Modifier `Community::getRouteKeyName()`** : Casserait les 4 routes admin (404 silencieux)
2. **Activer `/org/{organization}` sans canonical** : Duplicate content SEO
3. **Retirer les routes globales** : Casserait les notifications, Playwright, et Livewire (MessageThread)
4. **Changer Playwright `getCommunitySlug()`** : Nécessite d'abord l'activation des nouvelles routes

---

## Risk Register Summary

### HIGH (critical, need TASK before route migration)

| # | Risque | Localisation | Impact |
|---|--------|-------------|--------|
| H1 | Search cross-org data leak | SearchController | Services/requests de TOUTES les orgs exposés |
| H2 | Service/Request global access | ServiceController.show, RequestController.show | UUID-based cross-org data access |
| H3 | Profile cross-org aggregation | ProfileController.show | Données utilisateur aggrégées de toutes les orgs |
| H4 | Community route key asymmetry | Community (id) vs Organization (slug) | Migration blocker |
| H5 | BelongsToTenantScope désactivé sur global | Toutes les routes sans /{community}/ | Scope mort = pas d'isolation |

### MEDIUM (planifiés dans le blueprint)

| # | Risque | Localisation | Impact |
|---|--------|-------------|--------|
| M1 | Notification emails routes globales | app/Notifications/ | Liens sans contexte community |
| M2 | Duplicate routes (global + community) | 13 controllers | Incohérence, maintenance future |
| M3 | Playwright getCommunitySlug() regex fragile | tests/e2e/.../community.js | Casse si URL structure change |
| M4 | organizationRoute() helper unused | app/Support/helpers.php | Dead code si pas adopté |
| M5 | Pas de URL::defaults() | N/A | Chaque route() passe community manuellement |
| M6 | MessageThread Livewire zéro tenant | app/Livewire/MessageThread.php | Opère sans scoping |
| M7 | Explorer view → global routes | resources/views/livewire/explorer.blade.php | Incohérent avec le contexte tenant |

### LOW (surveillance, pas d'action immédiate)

| # | Risque | Localisation | Impact |
|---|--------|-------------|--------|
| L1 | Admin Community routes → id binding | AdminCommunityController | Fragile si getRouteKeyName change |
| L2 | Pagination links pas de passthrough communauté | 18 x ->links() | Fonctionne par URL context |
| L3 | Models community_id sans scope | User, BlogPost | Intentionnel — global ou user-scoped |
| L4 | Dashboard cross-org mix | DashboardController | User-scoped, pas de leak |
| L5 | Rate limiting incohérent | transactions.store (throttle) | Indépendant du routing |
| L6 | GoToProfile() global route | community.js:95 | Pas de communauté dans profile URL |

---

## Future Task Propositions

| TASK | Titre | Priorité | Description |
|------|-------|----------|-------------|
| TASK-072 | search-community-scope | HIGH | Ajouter filtrage tenant au SearchController. Créer route community-scoped /{community}/search |
| TASK-073 | notification-community-urls | MEDIUM | Passer community slug dans les notifications pour générer des URLs contextuelles |
| TASK-074 | global-route-tenant-enforcement | MEDIUM | Audit + hardening de l'isolation tenant sur les routes globales. Soit scope conditionnel, soit middleware global |
| TASK-075 | community-route-key-stabilization | MEDIUM | Documenter + fortifier l'asymétrie Community(id)/Organization(slug). Tests explicites |
| TASK-076 | playwright-url-abstraction | MEDIUM | Centraliser URL generation Playwright dans un helper pour future migration /org/ |
| TASK-077 | organization-dual-routes-activation | HIGH | Activer /org/{organization} routes en parallèle de /{community}. Canonical + redirect |
| TASK-078 | organization-route-cleanup | LOW | Nettoyage post-migration : suppression des doublons global/community, alias middleware |

---

## Micro-fixes Additive-Safe (potential pendant cette review)

1. ✅ Aucun — tous les problèmes identifiés nécessitent soit une TASK dédiée, soit sont des trouvailles architecture à documenter
2. La review est purement analytique et ne justifie PAS de modifications de code supplémentaires

---

## 2026-05-13 09:30:00 Europe/Paris

OPS workflow complet :
- check-task.sh TASK-071 ✅
- route:cache / route:clear ✅
- Commit + push branche ✅
- PR #28 créée, CI PostgreSQL ✅ (run #13)
- Merge --no-ff vers develop ✅
- Push develop ✅
- Cleanup branche locale + distante ✅
- PR #28 fermée ✅
- TASK status → MERGED ✅

---

# Review Notes

- **Passe 1** : Audit exhaustif du routing (16 points, livrables faible risque)
- **Passe 2** : Architecture review (9 axes, 6 HIGH risks identifiés, 7 MEDIUM, 6 LOW)
- **Découverte majeure** : `BelongsToTenantScope` inactif sur routes globales — root cause de la majorité des HIGH risks
- **Découverte critique** : Asymétrie Community(id)/Organization(slug) = migration blocker si mal gérée
- **Découverte architecture** : 13 controllers en dual-routes (global + community)
- **Zéro breaking change** dans cette review (analytique pure)
- **6 propositions TASK futures** documentées
- **Aucune modification de code** nécessaire dans cette itération
- Prêt pour finalization OPS