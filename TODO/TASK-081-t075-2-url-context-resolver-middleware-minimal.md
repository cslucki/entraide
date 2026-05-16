---
task_id: TASK-081
title: T075.2 — URL Context Resolver Middleware Minimal

status: DONE

owner: OPENCODE

contributors:
  - OPENCODE
  - CLAUDE_SONNET_4_6

branch: TASK-081-t075-2-url-context-resolver-middleware-minimal

priority: HIGH

created_at: 2026-05-16 20:49:18 Europe/Paris
updated_at: 2026-05-17 Europe/Paris

labels:
  - tenant
  - organization
  - middleware
  - routing
  - url-context

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: true

pr:
  status: NOT_READY
  url: null
---

# Objective

Implémenter un middleware minimal de résolution du contexte URL (URL Context Resolver) qui détermine l'Organization active pour chaque requête entrante, en suivant la stratégie définie par T075.1.

**Source de vérité** : `docs/architecture/T075.1-root-domain-tenant-resolution-strategy.md`

---

# Architecture Rules (T075)

- **Organization = Tenant**. Tenant boundary, security boundary, billing boundary, governance boundary.
- **Loop ≠ Tenant**. Loops are collaborative contexts, relational groups, operational spaces. Never tenants.
- **Partner ≠ Tenant**. Partner = co-branding / distribution channel. Never a security or DB isolation layer.
- **Community / community_id / current_community** = legacy technique temporaire.
- **Root domain n'est pas tenantless**. Toute route métier nécessite une Organization résolue.
- **/{feature}** résout l'Organization par défaut de la plateforme.
- **/{partnerSlug}/{feature}** résout l'Organization partenaire (future task).
- **Public ≠ global**.

---

# Ce qui a été livré (T075.2)

## Middleware : `app/Http/Middleware/ResolveUrlOrganization.php`

- **5 contextes URL** classifiés :
  1. Platform global — routes exactes et préfixes
  2. Default Organization — routes métier connues
  3. Partner slug — détection uniquement (résolution = future task)
  4. Authenticated personal — user → community_id → Organization
  5. Fail-safe — 404 pour routes métier connues sans résolution

- **Bypass fiable** :
  - `alreadyResolved()` — ne pas écraser si déjà résolue
  - `isCommunityPrefixedRoute()` — laisser `ResolveCommunity` gérer
  - `isPlatformGlobal()` — pas de DB, pas de résolution

- **Fail-safe 404** : routes métier connues sans Organization → `abort(404)`
- **Partner** : détection minimale, résolution = future task (aucun Partner model/table)
- **current_community legacy** : bindé si non déjà résolu
- **Listes statiques injectables** : `$platformGlobalExact`, `$platformGlobalPrefixes`, `$defaultOrganizationRoutes`, `$defaultOrganizationId`

## Enregistrement

- Alias `url.organization` dans `bootstrap/app.php` ✓
- Intégration web group : **désactivée** (voir Bloqueur)

## Tests : `tests/Feature/ResolveUrlOrganizationTest.php`

21 tests, 37 assertions — **all green**.

| Catégorie | Tests | Status |
|---|---|---|
| Platform global (vraies routes) | `/`, `/login`, `/register`, `/forgot-password`, `/mentions-legales`, `/admin/dashboard` | ✅ |
| Default Organization (test direct) | résout avec org, 404 sans org | ✅ |
| Dashboard user authentifié | résout org, 404 si pas d'org | ✅ |
| Dashboard guest | résout default org, 404 sans default | ✅ |
| Unknown route | passe transparent | ✅ |
| Community-prefixed | skip | ✅ |
| Already resolved | skip | ✅ |
| Legacy compatibility | bind current_community, ne pas override | ✅ |
| Registration | alias, web group (noté deferred) | ✅ |

---

# Bloqueur — Web group désactivé

**Problème** : activer `ResolveUrlOrganization` dans le groupe web casse **36 tests** sur 579.

**Cause racine** : 36 tests métier créent des users et/ou données sans Organization (community_id = null, pas de Community/Organization seed). Le middleware bloque ces requêtes avec 404 sur routes métier connues (comportement correct — mais les tests ne sont pas préparés).

**Fichiers impactés** (36 échecs) :

| Fichier | Nb tests | Cause |
|---|---|---|
| `FavoriteControllerTest` | 3 | users sans community_id |
| `FullExchangeFlowTest` | 3 | users sans community_id |
| `LoopActivityTrackingTest` | 1 | user sans community_id |
| `SearchControllerTest` | 8 | hits `/search` sans org |
| `ServiceControllerTest` | 8 | users sans community_id |
| `T07411RoutesTenantSafetyTest` | 5 | loops routes sans org |
| `TransactionControllerTest` | 6 | users sans community_id |
| `BanMiddlewareTest` | 1 | user sans community_id + `/dashboard` |
| `ResolveUrlOrganizationTest` | 1 | test web group lui-même |

**Décision** : ne pas corriger en masse dans T075.2. Web group = T075.3+.

---

# Handoff vers Claude Code

## État git

```
bootstrap/app.php                     |  6 ++++--  (alias + deferred web group comment)
public/build/manifest.json             |  2 +-     (sprite artifact, unrelated)
app/Http/Middleware/ResolveUrlOrganization.php  (nouveau, 217 lignes)
tests/Feature/ResolveUrlOrganizationTest.php   (nouveau, 314 lignes)
TODO/TASK-081-t075-2-url-context-resolver-middleware-minimal.md (mis à jour)
```

**579 tests passent, 21 ResolveUrlOrganization tests passent.**

## Fichiers modifiés (git diff --stat)

```
 bootstrap/app.php          | 6 ++++++
 public/build/manifest.json | 2 +-
 2 files changed, 7 insertions(+), 1 deletion(-)
```

## Nouveaux fichiers

```
 app/Http/Middleware/ResolveUrlOrganization.php
 tests/Feature/ResolveUrlOrganizationTest.php
 TODO/TASK-081-t075-2-url-context-resolver-middleware-minimal.md
```

## Recommandations pour T075.3

1. **Activation web group** : décommenter `ResolveUrlOrganization::class` dans `bootstrap/app.php`.
2. **Avant activation** : corriger les 36 tests pour créer un org (Community factory) dans `setUp()` ou par trait. Approche recommandée : trait `WithTestOrganization` qui crée une org de test et la lie à l'utilisateur.
3. **Tests concernés** : tous ceux listés ci-dessus. Aucun ne touche Blog/Services/Requests/Policies/API model — les corrections sont des fixtures, pas du métier.
4. **Partner** : créer Partner model/table + implémenter `resolvePartnerOrganization()`.
5. **Routes métier** : vérifier que chaque route connue est bien listée dans `$defaultOrganizationRoutes`.
6. **Rendre les listes configurables** via config (optionnel, pour éviter les statics).

---

# Review — Claude Sonnet 4.6 (2026-05-17)

## Décision : A — Middleware prêt, activation web group différée à T075.3+

### Vérifications effectuées

| Check | Résultat |
|---|---|
| Tests ciblés `ResolveUrlOrganizationTest` | 21/21 ✅ |
| Full suite | 579/579 ✅ |
| Middleware logique | Sain ✅ |
| bootstrap/app.php alias | Enregistré ✅ |
| Web group activation | Déactivée — délibéré ✅ |

### Analyse du middleware

Le middleware est architecturalement correct. Points inspectés :

- **5 contextes URL** : classifiés et gérés proprement.
- **Bypasses** : `alreadyResolved`, `isCommunityPrefixedRoute`, `isPlatformGlobal` — fiables.
- **Fail-safe 404** : comportement correct per T075.1 — toute route métier connue sans Organization résolue → 404.
- **Legacy compatibility** : `current_community` bindé si non déjà résolu.
- **Partner stub** : `resolvePartnerOrganization()` retourne null explicitement — scope futur documenté.

Observations mineures (non bloquantes) :
- `'/'` dans `$platformGlobalExact` est inatteignable par `segment(1)` — code mort inoffensif.
- La boucle `foreach` dans `isPlatformGlobal` est équivalente à `in_array` — lisible, sans impact.

### Pourquoi Decision A et non B

**B (activation minimale)** est hors scope sans élargir T075.2 :
1. Activer le web group → 36 régressions (tests sans fixture Organization).
2. Adoucir l'abort → affaiblit le contrat fail-safe architectural.
3. Sous-groupe de routes opt-in → modification routes/web.php = scope creep.

Les 36 tests échouent parce qu'ils créent des users/données sans `community_id` / Organization. Ce sont des **dettes de fixtures de test**, pas des bugs du middleware. Le middleware fait exactement ce que T075.1 spécifie.

**T075.2 objective = créer le middleware** (Minimal). L'activation production = T075.3.

### Risques résiduels documentés

| Risque | Mitigation |
|---|---|
| `resolveDefaultOrganization()` fait une requête DB `Community::where('is_active', true)->first()` en fallback — pas de cache | Acceptable pour T075.2 ; optimisation = T075.3+ |
| `$defaultOrganizationRoutes` liste statique — peut se désynchroniser des routes réelles | À vérifier à T075.3 avant activation web group |
| Tests métier (36) ne setup pas d'Organization | T075.3 doit créer un trait `WithTestOrganization` avant activation |

### Recommandation pour T075.3 (activation web group)

1. Créer trait `WithTestOrganization` qui crée une Community active et l'assigne à l'user dans `setUp()`.
2. Appliquer ce trait aux 36 tests concernés.
3. Décommenter `ResolveUrlOrganization::class` dans `bootstrap/app.php`.
4. Valider que les 36 tests repassent.
5. Valider full suite 579+/579+.

---

# Progress Log

## 2026-05-16 20:49:18 Europe/Paris

Task created by OPENCODE.
- T075.0, T075.1, T075.1A : MERGED dans develop.
- CI develop : verte.
- T075.2 créée via create-task.sh.
- Scope : URL Context Resolver Middleware minimal.

## 2026-05-16 — Implémentation

- Création `app/Http/Middleware/ResolveUrlOrganization.php` avec 5 contextes URL.
- Enregistrement alias `url.organization` dans `bootstrap/app.php`.
- Enregistrement web group puis désactivation (36 tests cassés, deferred to T075.3+).
- 21 tests unitaires et intégration (Option A = vraies routes, Option B = test direct handle).
- 579/579 tests passent dans la full suite.
- Pas de migration, pas de Partner, pas de modification de routes métier.
- Pas de correction hors scope — T075.2 pose le rail uniquement.
