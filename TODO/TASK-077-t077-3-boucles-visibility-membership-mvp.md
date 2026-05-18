---
task_id: TASK-077.3
title: Boucles Visibility & Membership MVP

status: IN_PROGRESS

owner: OPS

contributors:
  - CYRIL

branch: T077.3-t077-3-boucles-visibility-membership-mvp

priority: MEDIUM

created_at: 2026-05-18 21:01:15 Europe/Paris
updated_at: 2026-05-18 22:15:00 Europe/Paris

labels:
  - boucles
  - visibility
  - membership
  - mvp
  - organization-scoped

lock:
  status: LOCKED
  agent: OPS
  since: 2026-05-18 21:01:15 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

T077.3 — Boucles Visibility & Membership MVP.

Implémenter la visibilité Organization-scopée des Boucles (Loops) et un membership MVP.

Règles fondamentales :

- Organization = Tenant. Frontière de sécurité unique.
- Loop ≠ Tenant. Loop est un groupe collaboratif interne à une Organization.
- Partner ≠ Tenant. Partner est une entrée co-branding / distribution.
- Public ≠ Global. Une route publique peut être Organization-scopée.

Principes de résolution runtime :

- **fail-closed si aucune Organization résolue** — une requête sans Organization explicite ne doit pas leaking des données Loop cross-tenant.
- **aucune lecture DB Loop sur `/boucles` sans Organization explicite** — toute route Loop doit résoudre l'Organization avant toute requête.
- **aucun nouveau concept Community** — pas de nouvelle colonne, table, middleware, scope ou route qui introduit `community_id` ou `Community` dans le code.
- **community_id / current_community** — uniquement legacy temporaire, documenté comme tel. Préférer `organization_id` et `current_organization` partout dans le nouveau code.
- **Organization-scoping obligatoire** — les routes Boucles doivent être atteignables via `/{organization?}/boucles` ou `/{partnerSlug}/boucles`, jamais via `/boucles` sans contexte tenant.

---

# Planned Actions

- [ ] inspect architecture actuelle des Boucles (routes, controller, models, policies, middleware)
- [ ] inspect impacted files (routes/web.php, app/Livewire/Boucles/, app/Models/Loop.php, policies)
- [ ] implémenter résolution Organization obligatoire sur routes Boucles
- [ ] implémenter fail-closed si aucune Organization résolue (403/404)
- [ ] implémenter membership MVP (join/leave Boucle scoped à Organization)
- [ ] implémenter visibilité Boucles (publique Organization scopée vs privée)
- [ ] valider qu'aucune route `/boucles` sans Organization n'exécute de requête DB Loop
- [ ] run tests (PHPUnit + Playwright)
- [ ] inspecter console browser
- [ ] valider responsive
- [ ] valider tenant isolation

---

# Scope

- **Organization-scoped visibility** pour les Boucles
  - Boucle visible uniquement dans le contexte de son Organization
  - Boucle publique = visible par tous les membres de l'Organization
  - Boucle privée = visible uniquement par les membres de la Boucle
- **Membership MVP**
  - rejoindre une Boucle (join)
  - quitter une Boucle (leave)
  - scope obligatoire à l'Organization courante
- **Fail-closed**
  - middleware ou helper qui bloque si `current_organization` non résolu
  - pas de fallback à une valeur par défaut qui exposerait des données
- **Routes sécurisées**
  - toute route Boucles prefixée par `{organization}` ou `{partnerSlug}`
  - pas de route `/boucles` nue
- **Terminology cohérente**
  - `organization` dans le nouveau code
  - `current_organization` dans les middlewares/vues
  - legacy `community` inchangé (compatibility layer documenté)

---

# Out of Scope

- pas de code runtime (cette TASK est documentation + planification uniquement)
- pas de migration DB (pas de nouvelle colonne, table, index)
- pas de ChatLoop (hors scope — feature séparée)
- pas d'IA (hors scope — feature séparée)
- pas de websocket (hors scope — feature séparée)
- pas de refactor Community → Organization (migration séparée T077.x)
- pas de redesign global UI des Boucles
- pas de modification sur main / PROD
- pas de déploiement ALPHA
- pas de modification des transactions, points ledger, messaging, admin

---

# Architecture Rules

## Tenant Model

```
Organization = Tenant (single security boundary)
Loop ≠ Tenant (collaborative group inside Organization)
Partner ≠ Tenant (co-branding/distribution entry point)
Community ≠ Tenant (legacy concept, migration en cours)
```

## Resolution Priority

```
1. app('current_organization')  → canonical
2. app('current_community')     → legacy fallback (temporary)
3. fail-closed                  → 403/404 if neither resolved
```

## Route Design

```
GET  /{organization}/boucles              → index (Organization scopé)
GET  /{organization}/boucles/{loop}        → show (Organization scopé)
POST /{organization}/boucles/{loop}/join   → membership join
POST /{organization}/boucles/{loop}/leave  → membership leave
```

## Naming

- NOUVEAU CODE : `organization`, `organization_id`, `current_organization`
- LEGACY (temporaire) : `community`, `community_id`, `current_community` — ne pas étendre
- PAS de nouveau concept `Community` dans ce scope

## Security

- Tout accès Boucles sans Organization résolue → 403/404
- Pas de listing global de Boucles
- Membership operations scope à l'Organization courante
- Policy vérifie l'appartenance à l'Organization avant d'autoriser

---

# Tests Attendu

## PHPUnit / Feature Tests

- [ ] test : accès à `/boucles` sans Organization → 403 ou 404 (fail-closed)
- [ ] test : accès à `/{organization}/boucles` avec Organization valide → 200
- [ ] test : accès à `/{organization}/boucles/{loop}` → visibilité publique OK
- [ ] test : accès à `/{organization}/boucles/{loop}` → visibilité privée bloquée si non membre
- [ ] test : join Boucle → membre ajouté
- [ ] test : leave Boucle → membre retiré
- [ ] test : join Boucle d'une autre Organization → bloqué
- [ ] test : leave Boucle d'une autre Organization → bloqué
- [ ] test : listing Boucles ne contient que celles de l'Organization courante

## Browser / Playwright

- [ ] navigation Boucles avec Organization → affiche les Boucles
- [ ] join Boucle → UI reflète l'adhésion
- [ ] leave Boucle → UI reflète le départ
- [ ] pas de fuite de Boucles cross-Organization dans le DOM
- [ ] responsive validation (desktop + mobile)
- [ ] console errors = 0
- [ ] tenant isolation confirmée (deux onglets, deux Organizations)

## Validation

- [ ] PHPUnit green
- [ ] Playwright green
- [ ] SQLite runtime stable
- [ ] PostgreSQL runtime stable
- [ ] CI parity

---

# Progress Log

## 2026-05-18 21:01:15 Europe/Paris

Task created.

Owner:
OPS

Branch:
T077.3-t077-3-boucles-visibility-membership-mvp

Status:
IN_PROGRESS

## 2026-05-18 22:15:00 Europe/Paris

TASK file complété par OPS (OpenCode) — phase documentation avant implémentation.

Sections complétées :
- Objective : règles fondamentales, résolution runtime, principes
- Planned Actions : checklist détaillée
- Scope : Organization-scoped visibility, membership MVP, fail-closed, routes sécurisées, terminology
- Out of Scope : périmètre explicite (pas de code runtime dans cette TASK, migrations, ChatLoop, IA, etc.)
- Architecture Rules : tenant model, resolution priority, route design, naming, security
- Tests Attendu : PHPUnit feature tests, Playwright browser tests, validation stack
- Handoff notes : state actuel, pending actions, ownership

Prochaine étape :
- commit du TASK file sur branche distante
- passage à l'implémentation code (TASK suivante ou phase 2 de T077.3)

---

# Handoff

## Current State

TASK file documenté. Aucun code modifié. Git status clean.

## Pending Actions

1. Commit TASK file
2. Push branch distante
3. (next) Implementation code — Organization-scoped routes, middleware, membership

## Modified Files

- `TODO/TASK-077-t077-3-boucles-visibility-membership-mvp.md`

## Known Risks

- Legacy `community_id` scattered across codebase — ne pas refactor dans cette TASK
- Routes Boucles existantes peuvent ne pas être Organization-scopées — inspection nécessaire avant implémentation
- Playwright tests peut nécessiter fixtures Organization

## Ownership

- OPS (OpenCode) — documentation TASK file
- CYRIL — review + implementation coordination

---

# Tests

- [ ] feature tests (PHPUnit)
- [ ] browser validation (Playwright)
- [ ] responsive validation
- [ ] console inspection
- [ ] tenant isolation validation
- [ ] SQLite / PostgreSQL parity

---

# Test Results

Pending.

---

# Review Notes

Pending.
