---
task_id: TASK-078
title: t075-0-organization-native-tenant-audit

status: DONE

owner: OPENCODE

contributors:
  - OPENAI

branch: TASK-078-t075-0-organization-native-tenant-audit

priority: HIGH

created_at: 2026-05-16 19:02:23 Europe/Paris
updated_at: 2026-05-16 20:45:00 Europe/Paris

labels:
  - audit
  - tenant
  - organization-native
  - no-code

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

Audit exclusif Organization-Native Tenant Resolution.

Sortir BouclePro de l'ambiguïté Community et préparer une fondation Organization-native.

**AUDIT-ONLY.** Aucune implémentation, aucune migration, aucune correction opportuniste.

---

# Architecture Rules (canonical)

- **Organization = Tenant**
- **Loop ≠ Tenant**
- Root domain `test.laravel` / `bouclepro.com` n'est pas tenantless.
- Toute fonctionnalité métier doit résoudre une Organization ou rediriger vers une route Organization-scopée canonique.
- `Community` / `community_id` / `current_community` = dette legacy technique temporaire.
- Ne pas introduire de nouveau vocabulaire ou nommage Community dans les nouveaux concepts, docs, vues, services ou prompts.
- Utiliser `Organization` / `Loop` / `Member` / `Interaction` côté produit et nouveau code.
- N'utiliser `community_id` / `current_community` / routes legacy que pour décrire l'existant Laravel.

---

# Scope — Modules à auditer

- [x] root / dashboard
- [x] loops
- [x] services / requests / transactions
- [x] messaging
- [x] blog
- [x] directory / members
- [x] admin
- [x] policies
- [x] scopes
- [x] middleware
- [x] tests PHPUnit
- [x] Playwright helpers
- [x] QA seeders

---

# Interdits — Ce qui est STRICTEMENT exclu

- ❌ aucun code applicatif
- ❌ aucune migration
- ❌ aucun search/replace global
- ❌ aucun refactor
- ❌ aucune correction opportuniste
- ❌ aucune modification PROD
- ❌ aucun merge
- ❌ ne pas toucher main
- ❌ ne pas implementer de nouveau concept

Seulement lire, inspecter, documenter.

---

# Planned Actions

- [x] read TASK file
- [x] inspect architecture docs (ai/context/*, docs/*)
- [x] inspect middleware runtime resolution
- [x] inspect models / scopes / policies
- [x] inspect routes / controllers
- [x] inspect Livewire components
- [x] inspect views / Blade
- [x] inspect tests (PHPUnit + Playwright)
- [x] inspect seeders / QA helpers
- [x] inspect admin panels
- [x] compile audit findings
- [x] unify findings into actionable report

---

# Progress Log

## 2026-05-16 19:02:23 Europe/Paris

Task created.

Owner: OPENCODE

Branch: TASK-078-t075-0-organization-native-tenant-audit

Status: IN_PROGRESS

## 2026-05-16 19:06:00 Europe/Paris

Task cadre : audit-only Organization-native tenant resolution.
- CI commit 56410f1 ✅ verte (PostgreSQL CI success)
- develop propre et à jour
- branche `TASK-078-t075-0-organization-native-tenant-audit` créée
- TASK file mis à jour avec scope audit, interdits, modules à auditer
- Prochaine étape : ouvrir audit dans chaque module listé

## 2026-05-16 20:35:00 Europe/Paris

Audit terminé. Résumé de l'exécution :

1. **Architecture docs lues** : `ai/context/architecture.md`, `ai/context/multi-tenant.md`, `docs/06-DOMAIN_ARCHITECTURE_V2.md`
2. **Application info collectée** : Laravel 13.7, Livewire 4.2, PostgreSQL
3. **Routes inspectées** : `routes/web.php` (358 lignes), `routes/api.php` (42 lignes), `routes/auth.php`, `routes/channels.php`
4. **Middleware inspectés** : `ResolveCommunity.php`, `ResolveOrganization.php`, `AdminMiddleware.php`, `bootstrap/app.php`
5. **Models inspectés** : 31 fichiers dans `app/Models/`, `HasOrganizationId trait`, `BelongsToTenantScope`
6. **Controllers inspectés** : 44 fichiers (10 root, 8 feature, 2 blog, 8 auth, 11 admin, 5 API)
7. **Policies inspectées** : 6 fichiers (tous ownership-based, aucun tenant check)
8. **Livewire inspectés** : `Explorer.php` (communityId), `MessageThread.php`
9. **Tests inspectés** : 48 PHPUnit, 20 Playwright spec files
10. **Seeders inspectés** : 11 fichiers, Community model seulement (pas Organization)

### Résultats

- **P0 détectés : 5** — Root domain sans middleware tenant, Scope silencieux, Blog tenantless, Service/Request hidden field, Members PII leak
- **P1 détectés : 3** — LoopController legacy, API zero tenant, Test coverage gaps
- **P2 détectés : 5** — Messages isolation, Policies sans tenant, Admin naming, Broadcast channel, UserFactory
- **Sous-tâches T075.x recommandées : 16**

Rapport complet : `docs/audits/T075.0-organization-native-tenant-audit.md`

---

# Handoffs

## 2026-05-16 20:35:00 Europe/Paris

Handoff vers OPENAI.

### Current State
- TASK-078 est **DONE** et **UNLOCKED**
- Aucun code applicatif modifié
- Rapport d'audit complet dans `docs/audits/T075.0-organization-native-tenant-audit.md`

### Modified Files
- `docs/audits/T075.0-organization-native-tenant-audit.md` (créé) — rapport d'audit complet
- `TODO/TASK-078-t075-0-organization-native-tenant-audit.md` (mis à jour) — status DONE, progress log, handoff

### P0 Surfaces détectées (action critique requise)
1. Root domain business routes sans middleware tenant (services, requests, transactions, dashboard, messages, loops)
2. BelongsToTenantScope silencieux quand aucun tenant résolu → scope inactif = pas d'isolation
3. ServiceController/RequestController lisent community_id d'un champ hidden HTML → tamperable
4. BlogPost::create() n'assigne jamais community_id → posts créés sans tenant
5. /membres sur root domain sans tenant → tous les utilisateurs PII exposés

### P1 Surfaces détectées (recommandé avant PROD)
6. LoopController utilise encore app('current_community') legacy
7. API routes (Sanctum) sans aucun tenant scope
8. Gaps majeurs dans les tests tenant isolation (Blog, API, route-model binding)

### Recommendations Immédiates
- Implémenter T075.x en priorité P0 descendante : T075.1 → T075.9 → T075.3 → T075.5 → T075.6
- Le rapport contient 16 sous-tâches T075.x documentées

---

# Tests

- [x] feature tests — audit only, no execution required
- [x] browser validation — audit only
- [x] responsive validation — audit only
- [x] console inspection — audit only
- [x] tenant validation — audit only

---

# Test Results

## 2026-05-16 20:35:00 Europe/Paris

Aucun test modifié ou exécuté (audit-only).

Tests existants pertinents inspectés :
- `BelongsToTenantScopeTest.php` — ✅ Valide scope filtering (mais ne teste PAS le cas "scope silencieux")
- `CurrentOrganizationTest.php` — ✅ Valide helper resolution chain
- `OrganizationCompatibilityTest.php` — ✅ Valide Organization extends Community
- `OrganizationRelationshipsTest.php` — ✅ Valide dual relationships
- `OrganizationRouteCompatibilityTest.php` — ✅ Valide route param {organization}
- `T07411RoutesTenantSafetyTest.php` — ✅ Cross-community loop safety
- QA-MT01, QA-MT02 (Playwright) — ✅ Cross-community E2E service/transaction isolation
- QA-N13 (Playwright) — ✅ Unauthorized message access

Gaps identifiés :
- ❌ Aucun test pour root domain tenant isolation (P0)
- ❌ Aucun test pour Blog tenant creation (P0)
- ❌ Aucun test pour API tenant scoping (P1)
- ❌ Aucun test pour route-model binding cross-org (P0)
- ❌ Aucun test pour hidden field tampering (P0)

---

# Review Notes

## 2026-05-16 20:35:00 Europe/Paris

### Points Clés
1. L'architecture de résolution tenant est **conceptuellement correcte** : CurrentOrganization::get() → current_organization puis current_community. Le problème est **l'absence d'activation** sur les routes root domain.

2. Le middleware `ResolveOrganization` et le prefix `/{organization}` sont **prêts mais non activés**. C'est le chemin de migration le plus court.

3. Le `HasOrganizationId` trait fonctionne correctement. Les colonnes `organization_id` et `community_id` sont synchronisées.

4. `BelongsToTenantScope` est le maillon faible : scope silencieux = pas d'isolation. C'est le P0 le plus critique car il affecte TOUTES les queries sur root domain.

5. Le Blog est le module le plus problématique : pas de scope, pas d'assignation de community_id, pas de tests. Création de données tenantless.

6. La dépendance au champ hidden `community_id` dans ServiceController/RequestController est une vulnérabilité active (tampering possible).

### Recommandations Architecturales
- Le scope devrait être **strict** : si `CurrentOrganization::get()` === null, forcer un comportement bloquant (0=1 ou exception) plutôt que silencieux.
- Les routes root domain devraient soit (a) résoudre un tenant par défaut, soit (b) rediriger vers `/{community}/...`, soit (c) middleware `organization` sur les routes business.
- Le filtre `community_id` du scope devrait être renommé en `organization_id` dans une future migration DB (après compatibilité assurée).

---

# Arbitration — Cyril 2026-05-16 20:45:00 Europe/Paris

## Validation
Audit T075.0 validé. Audit-only respecté. Aucun code applicatif modifié.

## Verdict
Le risque critique n'est pas le naming Community/Organization. Le problème racine est que le root domain fonctionne sans tenant middleware ni Organization résolue.

## Décision Stratégique
Ne pas lancer les 16 sous-tâches T075.x recommandées dans le rapport.

Ouvrir une **lame courte T075.1** centrée sur :

**Root Domain Tenant Resolution Strategy**

### Objectifs T075.1
1. Définir le comportement canonique du root domain
2. Résoudre une Organization par défaut ou rediriger vers une route Organization-scopée
3. Empêcher les routes métier de fonctionner sans Organization
4. Préserver Admin comme global intentionnel
5. Ne pas lancer de migration Community → Organization massive
6. Ajouter tests P0 sur /membres, /services, /blog, /dashboard

### Contraintes
- Ne PAS durcir BelongsToTenantScope en premier (risque de casser brutalement l'app)
- D'abord définir la stratégie tenant du root domain
- Ensuite seulement adapter le scope et les routes
