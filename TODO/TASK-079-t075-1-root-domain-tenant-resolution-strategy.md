---
task_id: TASK-079
title: T075.1 — Root Domain Tenant Resolution Strategy

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-079-t075-1-root-domain-tenant-resolution-strategy

priority: HIGH

created_at: 2026-05-16 19:23:43 Europe/Paris
updated_at: 2026-05-16 19:25:29 Europe/Paris

labels:
  - strategy
  - tenant-resolution
  - architecture
  - root-domain

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: false

pr:
  status: NOT_READY
  url: null
---

# T075.1 — Root Domain Tenant Resolution Strategy

## Objective

Définir le comportement canonique du root domain (`test.laravel` / `bouclepro.com`)
afin qu'aucune route métier ne fonctionne sans **Organization** résolue.

**Cette tâche est STRATEGY-FIRST.**
Elle prépare la décision d'architecture avant implémentation.
Aucun code applicatif (app/, routes/, resources/, database/, config/) ne doit être modifié dans T075.1.

---

## Contexte

- T075.0 est mergée dans develop (commit f7c0db3).
- T075.0 a audité l'état actuel du tenant scoping sans modifier de code applicatif.
- Aucun code applicatif modifié par T075.0.
- CI develop stable (SQLite + PostgreSQL).
- Ce repo est multi-agent. Toute décision architecturale doit être documentée pour être interprétable par GLM, Claude, OpenCode, Codex, Gemini, Jules, DeepSeek.

---

## Architecture Rules

- **Organization = Tenant.**
- **Loop ≠ Tenant.**
- Le root domain n'est **pas** tenantless.
- Aucune fonctionnalité métier ne doit charger, afficher, créer ou modifier des données sans **Organization** résolue.
- `/admin` reste **global intentionnel** (ne nécessite pas d'Organization).
- `Community` / `community_id` / `current_community` = legacy technique temporaire, utilisable uniquement quand nécessaire pour décrire l'existant Laravel.
- **Ne pas introduire de nouveau vocabulaire Community** dans le produit, les vues, les docs ou le nouveau code.
- Utiliser **Organization / Loop / Member / Interaction** côté produit et nouveau code.

---

## Questions stratégiques à trancher dans T075.1

### 1. Comportement de `/` (root)

- Que doit faire `/` ?
- Doit-il rediriger vers `/dashboard` si une Organization est résolue ?
- Doit-il afficher une page d'accueil globale (marketing/Landing) ?
- Doit-il afficher un sélecteur d'Organization si l'utilisateur en a plusieurs ?
- Doit-il afficher une page "aucune Organization" si l'utilisateur est authentifié mais sans Organization ?

### 2. Comportement de `/dashboard`

- Que doit faire `/dashboard` sans Organization résolue ?
- Que doit faire `/dashboard` avec Organization résolue ?
- Un utilisateur non authentifié peut-il accéder au dashboard ?

### 3. Routes métier : `/membres`, `/echanges`, `/boucles`

- Ces routes nécessitent-elles toujours une Organization résolue ?
- Que doit-il se passer si l'Organization n'est pas résolue ?
  - Redirection 302 ?
  - Erreur 403 ?
  - Erreur 419 ?
  - Page explicite "Sélectionnez une Organization" ?

### 4. Autres routes : `/services`, `/requests`, `/transactions`, `/messages`, `/blog`

- Même question : doivent-elles toutes exiger une Organization résolue ?
- Le blog est-il global ou scoped par Organization ?

### 5. Utilisateur authentifié avec Organization

- Résolution automatique par middleware ?
- Résolution via subdomain (org.bouclepro.com) ?
- Résolution via session ?
- Résolution via default organization sur le User model ?

### 6. Utilisateur authentifié sans Organization

- Que faire ?
- Page dédiée "Vous n'appartenez à aucune organization" ?
- Proposition de créer une Organization ?
- Sélecteur d'Organization (si invité mais pas encore accepté) ?

### 7. Visiteur non authentifié

- Toutes les routes métier doivent-elles exiger auth ?
- Landing page / marketing : auth optionnelle ?

### 8. Routes globales (ne nécessitant PAS d'Organization)

- Lesquelles listons-nous explicitement ?
  - `/login`, `/register`, `/password/*`, `/auth/*`
  - `/admin/*` (global intentionnel)
  - `/api/*` ? (à décider — certaines API sont internes à une Organization)
  - `/` landing ? (si landing page)

### 9. Justification `/admin` global

- **Pourquoi /admin reste global intentionnel** :
  - L'admin gère la plateforme entière, pas une Organization spécifique.
  - Les admins voient toutes les Organizations.
  - L'admin est un rôle système, pas un rôle tenant.
  - Documentation : `docs/09-ORGANIZATION_MIGRATION_EXECUTION_PLAN.md`

### 10. Redirection vers Organization par défaut

- Faut-il rediriger automatiquement vers l'Organization par défaut de l'utilisateur ?
- Si oui, comment est déterminée l'Organization par défaut ?
  - `users.default_organization_id` ?
  - Dernière Organization utilisée (session) ?
  - Organization avec le plus récent `Member` ?

### 11. Résolution d'Organization par défaut

- Middleware dédié (`ResolveTenant`) ?
- Binding dans le service container ?
- Scopping global Eloquent ?

### 12. Activation de `/org/{organization}`

- Faut-il activer le pattern `/org/{organization}` **maintenant** ou **plus tard** ?
  - Avantage : routage explicite, pas d'ambiguïté.
  - Risque : refactor massif des routes, URLs, liens, tests.
  - Suggestion probable : plus tard (T075.3+).

---

## Contraintes strictes T075.1

- [ ] Commencer par **stratégie** (cette tâche).
- [ ] Ne **pas** durcir `BelongsToTenantScope` dans T075.1.
- [ ] Ne **pas** corriger `BlogPost` dans T075.1.
- [ ] Ne **pas** corriger `ServiceController` / `RequestController` hidden `community_id` dans T075.1.
- [ ] Ne **pas** corriger Policies dans T075.1.
- [ ] Ne **pas** corriger API dans T075.1.
- [ ] Ne **pas** lancer de migration DB.
- [ ] Ne **pas** lancer de migration massive Community → Organization.
- [ ] Ne **pas** faire de giant search/replace.
- [ ] Ne **pas** faire de refactor global.
- [ ] Ne **pas** modifier PROD.
- [ ] Ne **pas** merger.
- [ ] **Ne lancer aucune implémentation** avant validation Cyril / COCKPIT.

---

## Planned Actions

- [x] vérifier état develop clean avant ouverture
- [x] créer branche dédiée T075.1
- [x] cadrer TASK file T075.1
- [x] documenter le problème root domain tenant resolution
- [x] écrire strategy document dans docs/architecture/T075.1-root-domain-tenant-resolution-strategy.md
- [ ] préparer handoff CODE strategy-first
- [ ] attendre validation COCKPIT avant toute implémentation

---

# Progress Log

## 2026-05-16 19:25:29 Europe/Paris

TASK finalized by OPENCODE.
- Strategy document validated: docs/architecture/T075.1-root-domain-tenant-resolution-strategy.md
- COCKPIT: APPROVE
- Status set to DONE. Lock UNLOCKED.
- public/build/manifest.json reverted (unrelated Vite hash change).
- No application code modified.

## 2026-05-16 19:23:43 Europe/Paris

Task created by OPENCODE.
Branch: TASK-079-t075-1-root-domain-tenant-resolution-strategy.
Base: develop (merge T075.0 f7c0db3, clean).
Status: IN_PROGRESS.
TASK file cadré avec objectif strategy-first, questions architecture, contraintes strictes.
Aucun code applicatif modifié.

## 2026-05-16 19:45:00 Europe/Paris

Strategy research complete. Documents inspected:
- T075.0 audit (docs/audits/T075.0-organization-native-tenant-audit.md)
- ai/context/architecture.md
- docs/06-DOMAIN_ARCHITECTURE_V2.md, docs/07-GLOSSARY.md, docs/08-COMMUNITY_MIGRATION_STRATEGY.md, docs/09-ORGANIZATION_MIGRATION_EXECUTION_PLAN.md
- routes/web.php, routes/auth.php, routes/api.php
- app/Http/Middleware/ResolveCommunity.php, app/Http/Middleware/ResolveOrganization.php
- app/Http/Controllers/HomeController.php, app/Http/Controllers/DashboardController.php
- app/Support/Tenancy/CurrentOrganization.php

Key findings:
- Root domain routes have NO tenant middleware. ResolveCommunity only activates under /{community} prefix.
- CurrentOrganization::get() checks app('current_organization') then app('current_community') — both null on root domain.
- BelongsToTenantScope silently skips when tenant is null — no WHERE clause = cross-org data leak.
- HomeController::members() dumps ALL users (P0) when communityId is null.
- DashboardController has no Org filtering — relies on BelongsToTenantScope which is silent.
- Routes /membres and /loops lack `auth` middleware — expose data to unauthenticated visitors.

Strategy document written:
- docs/architecture/T075.1-root-domain-tenant-resolution-strategy.md
- All 12 questions answered with explicit architecture decisions.
- Route matrix with current MW, needs auth, needs org, strategy columns.
- Task breakdown: T075.2 through T075.9 with dependencies, acceptance criteria.
- Sequence & dependencies mapped.
- Test plan (PHPUnit + Playwright).
- Risk register with mitigations.
- Modified files catalog per task.
- 10 architecture decisions (AD01-AD10).

## 2026-05-16 20:30:00 Europe/Paris

Correction stratégie après review COCKPIT.
Document docs/architecture/T075.1-root-domain-tenant-resolution-strategy.md corrigé.

Corrections appliquées :
- `/boucles` clarifié : legacy à déprécier. Pas le concept Loop. Cible : `/partners`.
- `Loop` = groupe collaboratif interne à une Organization. N'est pas un tenant.
- `Partner` = entrée co-branding / distribution. N'est pas un tenant.
- `Organization` = Tenant. Frontière de sécurité unique.
- `Platform` = système global BouclePro.
- Section Partner/Co-branding Terminology ajoutée (section 5).
- Tableau routes partenaires ajouté dans Route Matrix.
- `/` corrigé : featured services cross-org non validés, stats agrégées uniquement.
- `/blog/*` corrigé : plus "global OK". Surface P0. Décision T075.5 requise.
- AD06 et AD07 corrigés.
- Ordre des tâches révisé : T075.2 → T075.3 → T075.4 → T075.5 → T075.6 → T075.7 → T075.8 → T075.9 → T075.10 → T075.11.
- Portée T075.2 minimale : pas de BlogPost, pas de hidden community_id, pas de Policies, pas d'API, pas de BelongsToTenantScope hardening, pas de renommage /boucles, pas de création /partners.
- Noms de controllers corrigés : "controllers concernés" au lieu de noms inventés.
- T075.6 = Legacy /boucles audit + repositioning /partners.
- T075.11 = Playwright root-domain tenant tests.
- Acceptance criteria, Risk register, Files modified, Summary mis à jour.

Aucun code applicatif modifié.
Aucune migration.
Tests non exécutés (strategy-only).

## 2026-05-16 20:50:00 Europe/Paris

Ajout section 6 "Organisation Scoping Rule — All Features (Current & Future)".
Règle absolue : toute feature métier actuelle et future doit être Organization-scopée.
Pattern cible : /{feature} = default Org, /{partnerSlug}/{feature} = Org partenaire.
Routes Platform globales autorisées listées explicitement (/, /login, /register, /password/*, /mentions-legales, /sitemap.xml, /partners, /admin/*).

Ajout section 7 "Documentation Alignment Required".
Liste documents ai/context/ et docs/ à mettre à jour après validation T075.1,
avec mise à jour requise détaillée par fichier.

Ajout AD11 dans Architecture Decisions.
Renumérotation sections 7→15.

Aucun code applicatif modifié.
Aucune migration.
Tests non exécutés (strategy-only).

## 2026-05-16 21:15:00 Europe/Paris

Correction finale COCKPIT avant validation. 6 corrections appliquées sur le document stratégique.

**Correction 1 — Section 6 / Pattern cible**
- `/{feature}` ne résout plus "l'Organization par défaut du user connecté" mais "l'Organization par défaut de la plateforme".
- Exemples mis à jour : "Organization par défaut de la plateforme".

**Correction 2 — Blog n'est plus TBD**
- Blog désormais Organization-scopé. Plus de débat "global OU Organization-scopé".
- AD06 mis à jour : `Blog Organization-scopé`.
- T075.5 renommé : "Blog Organization Scoping + Containment".
- T075.5 objectif : corriger BlogPost tenantless, scoper routes, ajouter tests.
- Acceptance criteria #6 mis à jour.
- Summary blog line corrigé.

**Correction 3 — URL Context Resolution Order**
- Nouvelle sous-section dans section 6.
- 5 niveaux : Platform global → Default Organization → Partner slug → Authenticated personal → Fail-safe.
- Clarifie quand quelle Organization est résolue.

**Correction 4 — T075.2 scope mis à jour**
- T075.2 ne fait plus seulement session + user.community_id.
- Prépare résolution minimale par contexte URL (5 niveaux).
- Acceptance criteria étendus avec chaque contexte.

**Correction 5 — T075.5 renommé**
- Ancien : "Blog Tenant Strategy + Containment"
- Nouveau : "Blog Organization Scoping + Containment"
- Description mise à jour : décision déjà prise, actions restantes listées.

**Correction 6 — Risk register, Sequence, T075.11 blog line mis à jour**

Aucun code applicatif modifié.
Aucune migration.
Tests non exécutés (strategy-only).

## 2026-05-16 21:45:00 Europe/Paris

Correction mécanique finale avant review OPENAI.
3 corrections appliquées sur le document stratégique.

**Correction 1 — Q4 / blog**
- Remplacé "NOT resolved in T075.1. Décision future" par "Organization-scopé. `/blog` = Organization par défaut de la plateforme. `/{partnerSlug}/blog` = Organization partenaire."
- Supprimé le bloc "NE PAS déclarer global OK. Décision future requise : global OU Organization-scopé."
- Remplacé par "Blog Organization-scopé (AD06). Plus de débat. T075.5 doit corriger BlogPost tenantless."

**Correction 2 — Q11 / resolution sources**
- Remplacé l'ancienne résolution 3 niveaux (session → user.community_id → first active Community).
- Nouvelle résolution par contexte URL : Platform global → Default Org → Partner slug → Authenticated personal → Fail-safe.
- Chaque contexte a sa propre stratégie de résolution.

**Correction 3 — Section 9 dependency diagram**
- `T075.5 (blog decision)` → `T075.5 (blog Organization scoping + containment)`

Aucun code applicatif modifié.
Aucune migration.
Tests non exécutés (strategy-only).

## 2026-05-16 22:15:00 Europe/Paris

Corrections post-review OPENAI avant validation finale COCKPIT.
6 corrections appliquées.

**Correction 1 — Q5 authenticated user**
- Remplacé la résolution unique "session → user.community_id".
- Nouvelle formulation : la résolution dépend du contexte URL (routes personnelles → Org du user / routes root métier → Org par défaut plateforme / routes partner slug → Organization partenaire / routes globales → aucune).

**Correction 2 — Blog route matrix**
- `Needs Org` corrigé : `No` → `Yes`.
- Blog est public mais pas global. Public ≠ global.

**Correction 3 — Partner route matrix**
- `/partners` = Platform global (Yes). Pas d'Organization.
- `/partners/{partnerSlug}` = Redirect vers `/{partnerSlug}`.
- `/{partnerSlug}` = Org-scopé (Needs Org = Yes, Global = No). Landing publique mais pas globale.
- `/{partnerSlug}/blog/explorer/membres/loops` = Org-scopés (Needs Org = Yes).
- Règle ajoutée : **public ≠ global**.

**Correction 4 — T075.2 renommé**
- Ancien : "Root Domain Organization Resolution Middleware"
- Nouveau : "URL Context Resolver Middleware minimal"
- Logique clarifiée : classification du contexte URL + bind current_organization + fail-safe.
- Périmètre strict élargi : pas de modèle Partner, pas d'implémentation complète Partner, pas de migration DB.

**Correction 5 — Section 9**
- Déjà correct après corrections précédentes. `T075.5 (blog Organization scoping + containment)`.

**Correction 6 — Documentation sections 5 (Partner Terminology) et 6 (URL Order) mises à jour**
- Cohérence maintenue avec les changements ci-dessus.

Aucun code applicatif modifié.
Aucune migration.
Tests non exécutés (strategy-only).

## 2026-05-16 22:30:00 Europe/Paris — FINAL

**Validation COCKPIT : APPROVE.**
Document stratégique validé. T075.1 terminé côté CODE.

Rappel des points validés :
- Q5 distingue routes personnelles authentifiées, root métier, partner slug, Platform global.
- Blog Organization-scopé : `/blog` = Org par défaut plateforme ; `/{partnerSlug}/blog` = Org partenaire. Plus de débat global OU Organization-scopé.
- Partner routes corrigées : public ≠ global.
- T075.2 bornée comme URL Context Resolver Middleware minimal.
- Partner ≠ Tenant. Loop ≠ Tenant. Organization = Tenant.
- Community / community_id / current_community = legacy technique temporaire.
- Toutes features métier actuelles et futures Organization-scopées.
- Documentation Alignment Required listé pour ai/context, docs, AGENTS.md, CLAUDE.md.

Corrections finales :
- Q5 typo fix : ``, /login`` → `` `/`, `/login` ``
- Recommended execution order : T075.2 renommé "URL Context Resolver Middleware minimal"
- Dependency diagram : T075.2 label mis à jour

**État final :**
- status : DONE
- lock : UNLOCKED
- Code : aucun modifié
- Migrations : aucune
- Tests : non exécutés (strategy-only)
- Prêt pour : OPS → check-task.sh → finalize-task.sh

## 2026-05-16 23:00:00 Europe/Paris — DELTA REVIEW

**OPENAI delta review : aucun point bloquant restant.**
T075.1 validée comme stratégie sans réserves.

Aucun changement supplémentaire nécessaire.
Statut DONE confirmé.

---

# Handoffs

| Date | From | To | Notes |
|------|------|----|-------|
| 2026-05-16 19:23 | OPENCODE | — | Strategy phase. COCKPIT validation pending. |
| 2026-05-16 22:30 | OPENCODE | OPS | Document validé. Prêt pour check-task.sh et finalize-task.sh. |

Next handoff target: OPS (check/finalize/merge).

---

# Tests

- [ ] feature tests (post-strategy)
- [ ] browser validation (post-strategy)
- [ ] responsive validation (post-strategy)
- [ ] console inspection (post-strategy)
- [ ] tenant validation (post-strategy)

---

# Test Results

Pending. No tests to run at this stage.

---

# Review Notes

**Status**: DONE.
**Phase**: Strategy (validé COCKPIT).
**Code modified**: None.
**Database migrations**: None.
**PROD**: Not touched.
**main**: Not touched.
**Strategy document**: docs/architecture/T075.1-root-domain-tenant-resolution-strategy.md (validé, 15 sections).
**Key decisions**: AD01-AD11. Clarifications : /boucles = legacy, Loop ≠ tenant, Partner ≠ tenant, Platform = global, Organisation Scoping Rule (AD11). Blog Organization-scopé (AD06).
**Blog status**: Organization-scopé. `/blog` = Organization par défaut plateforme. `/{partnerSlug}/blog` = Organization partenaire.
**URL Context Resolution**: 5 niveaux documentés (Platform global → Default Org → Partner slug → Authenticated personal → Fail-safe).
**Implementation**: T075.2 (URL Context Resolver Middleware minimal) peut commencer.