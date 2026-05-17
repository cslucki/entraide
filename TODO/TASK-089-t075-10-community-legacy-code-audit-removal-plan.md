---
task_id: TASK-089
title: T075.10 — Community Legacy Code Audit & Removal Plan

status: MERGED

owner: OPENCODE

contributors: []

branch: TASK-089-t075-10-community-legacy-code-audit-removal-plan

priority: HIGH

created_at: 2026-05-17 12:01:18 Europe/Paris
updated_at: 2026-05-17 12:01:18 Europe/Paris

labels:
  - audit
  - migration
  - organization
  - community-removal

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: 2026-05-17 12:01:18 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Produce a comprehensive audit and removal plan for all remaining Community legacy code in the BouclePro codebase.

This is an **audit and planning task only**. No implementation. No code changes. No migrations. No removals.

The deliverable is a structured document: `docs/audits/T075.10-community-legacy-code-audit-removal-plan.md`

---

# Architecture Rules (Reference)

These rules govern the audit and all future removal work:

- **Organization = Tenant** — security, billing, governance boundary
- **Loop ≠ Tenant** — Loop is a collaborative group within an Organization
- **Partner ≠ Tenant** — Partner is a co-branding/distribution entry
- **Public ≠ Global** — a public route may still be Organization-scoped
- **Root domain is NOT tenantless** — business routes resolve an Organization
- **Community / community_id / current_community / ResolveCommunity = legacy temporary**
- **No new Community concept shall be introduced**
- **Organization / Loop / Member / Interaction** are the canonical product terms
- **community_id is tolerated only as a transition legacy column imposed by the current schema**
- **All remaining legacy usage must be documented and classified**

---

# Scope

## In Scope

- Audit all references to `Community`, `community_id`, `current_community`, `ResolveCommunity`, `BelongsToTenantScope` across the entire codebase
- Classify each reference by layer: database, models, middleware, routes, controllers, policies, Livewire, views, tests, config
- Identify safe removal order per the mandatory migration order
- Document dependencies and coupling between legacy Community references
- Produce a prioritized, incremental removal roadmap

## Out of Scope (FORBIDDEN in this task)

- **No code modifications** in `app/`, `routes/`, `resources/`, `database/`, policies, middleware, or APIs
- **No DB migrations**
- **No mass deletions or giant search/replace operations**
- **No refactoring or rewriting of existing code**
- **No Playwright test changes**

---

# Planned Actions

- [x] create task branch and TASK file
- [x] audit: search all files referencing Community / community_id / current_community / ResolveCommunity / BelongsToTenantScope
- [x] classify references by migration layer (database → models → middleware → routes → controllers → policies → Livewire → views → PHPUnit → Playwright)
- [x] identify coupling and dependency chains between references
- [x] assess risk level per reference (low / medium / high)
- [x] determine safe removal order and grouping
- [x] write audit document: `docs/audits/T075.10-community-legacy-code-audit-removal-plan.md`
- [x] mark task DONE

---

# Deliverable

**Single output file:** `docs/audits/T075.10-community-legacy-code-audit-removal-plan.md`

Structure:

1. Executive Summary
2. Architecture Context & Rules
3. Audit Methodology
4. Findings by Layer
   - Database (columns, indexes, constraints referencing community_id)
   - Models (Community model, traits, relationships, scopes)
   - Middleware (ResolveCommunity, tenant resolution)
   - Routes (community-scoped route parameters, middleware groups)
   - Controllers (community parameter injection, ResolveCommunity calls)
   - Policies (community-based authorization)
   - Livewire (community property bindings, ResolveCommunity usage)
   - Views (Blade templates referencing community)
   - Tests (PHPUnit feature tests with community assertions)
   - Playwright (E2E selectors and flows with community context)
   - Config (community-related config entries)
   - Other (migrations, seeders, factories, helpers)
5. Dependency & Coupling Map
6. Risk Assessment per Reference
7. Prioritized Removal Roadmap (incremental, safe, migration-order compliant)
8. Appendix: Full Reference List

---

# Progress Log

## 2026-05-17 12:01:18 Europe/Paris

Task created.

Owner: OPENCODE
Branch: TASK-089-t075-10-community-legacy-code-audit-removal-plan
Status: IN_PROGRESS

## 2026-05-17 Europe/Paris

Audit completed. Comprehensive report written.

Key findings:
- ~2,000+ legacy Community references across ~150+ files
- P0 (critical): 2 items — BelongsToTenantScope source de vérité tenant, HasOrganizationId stratégie Organization-first
- P1 (high): ~10 items — middleware, controllers, services
- P2 (low): ~50+ items — docs, migrations, seeders, factories, tests, routes, views

Priority roadmap defined in 22 future task proposals across 6 phases.

Deliverable: `docs/audits/T075.10-community-legacy-code-audit-removal-plan.md`
Status: DONE
Lock: UNLOCKED

## 2026-05-17 Europe/Paris (Corrections OPENAI)

All 10 correction requests applied:
- Classification section added (6 categories)
- P0/P1/P2 adjusted per review
- HasOrganizationId and BelongsToTenantScope reformulated
- ResolveOrganization → P1, {community} param → P2
- Roadmap disclaimer + 22 decoupled future tasks
- DB migration marked strictly future
- Phase 3 routes/E2E split into 5 sub-tasks

---

# Handoffs

No handoff needed — task is audit-only and complete.

---

# Tests

This is an audit-only task. No code changes = no test execution required.

- [x] no code modifications
- [x] no DB migrations
- [x] audit document validates against scope rules
- [x] git diff shows only TASK file + audit report (no runtime code changes)

---

# Test Results

N/A — audit task, no code to test.
Validation performed:
- `git status` confirmed only 2 untracked files: TASK file + audit report
- No `app/`, `routes/`, `resources/`, `database/`, policies, middleware, or API files modified
- No staged changes, no unstaged changes, no modified tracked files

---

# Modified Files

- `TODO/TASK-089-t075-10-community-legacy-code-audit-removal-plan.md` — status DONE, lock UNLOCKED, progress log updated
- `docs/audits/T075.10-community-legacy-code-audit-removal-plan.md` — comprehensive audit report (new file)

---

# Review Notes

## Verdict OPENAI: CHANGES REQUESTED (2026-05-17)

OPENAI review requested the following corrections to the audit report:

1. **Ajouter section classification** — ✅ 6 catégories : Supprimable maintenant, Remplaçable par Organization runtime, À garder temporairement car colonne DB legacy, À migrer plus tard via migration DB dédiée, À documenter seulement, À tester avant suppression.

2. **Reformuler P0** — ✅ Plus de "fix before next feature". Remplacé par "risque critique à séquencer dans une tâche dédiée" / "ne déclenche aucune correction dans T075.10".

3. **Ajuster priorités** — ✅ P0 réduit à 2 items (BelongsToTenantScope + HasOrganizationId). ResolveOrganization reclassé P1. {community} param reclassé P2.

4. **Reformuler HasOrganizationId** — ✅ Plus d'inversion directe. Stratégie Organization-first à définir dans une tâche dédiée.

5. **Reformuler BelongsToTenantScope** — ✅ Plus de "No risk". Reformulé "P0 sécurité avec validation complète car organization_id peut être nullable/stale".

6. **Reclasser ResolveOrganization** — ✅ P1 dette conceptuelle (pas P0).

7. **Reclasser {community} route param** — ✅ P2 (pas P0), sauf fuite sécurité concrète prouvée.

8. **Clarifier roadmap** — ✅ Avertissement explicite "T075.10 ne déclenche aucune correction ; chaque ligne future doit devenir une tâche séparée, une branche, un TASK file." Roadmap convertie en 22 tâches futures proposées.

9. **Découper Phase 3 (routes/E2E)** — ✅ Découpée en 5 sous-tâches : route naming, param naming, E2E helpers, E2E specs, views cleanup.

10. **Phase DB migration** — ✅ Marquée "strictement future, dédiée, non ouverte par T075.10".

## Applied Corrections

Fichiers modifiés :
- `docs/audits/T075.10-community-legacy-code-audit-removal-plan.md` — corrections documentaires appliquées
- `TODO/TASK-089-t075-10-community-legacy-code-audit-removal-plan.md` — review notes mises à jour

Aucun fichier runtime modifié. Aucune migration. Aucun commit/push.

---

# Blockers

None. Task completed successfully.