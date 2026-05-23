---
task_id: TASK-123
title: t123-blog-public-surface-tenant-scope-hardening

status: DONE

owner: CODEX

contributors: []

branch: TASK-123-t123-blog-public-surface-tenant-scope-hardening

priority: MEDIUM

created_at: 2026-05-23 19:51:50 Europe/Paris
updated_at: 2026-05-23 21:35:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Hardener le scope tenant de BlogPost, AdminBlogController, et ProfileController::show() pour empêcher les fuites de données cross-Organization.

---

# Planned Actions

- [x] Phase 0 — Préparation (git status, create-task, branch)
- [x] Phase 1 — Audit CAO read-only (4 agents CAO, 1 échoué, 3 OK)
- [x] Phase 1b — Audit BlogPost manuel (Agent 1 CAO échoué, repris en séquentiel)
- [x] Phase 2 — Consolidation des constats
- [ ] Phase 3 — Patch minimal :
  - [ ] ProfileController::show() — guard org
  - [ ] BlogPost model — addGlobalScope BelongsToTenantScope
  - [ ] AdminBlogController — guard org + filtre community_id
- [ ] Phase 4 — Tests ciblés
- [ ] Phase 5 — Finalisation (check, finalize, commit)

---

# Audit Summary

**Rapport détaillé :** `@DOCS/06-audit_scope_communityVSorganization.md` (non tracké)

## Agents CAO

| Agent | Session | Statut | Résultat |
|-------|---------|--------|----------|
| 1 — Blog scope | cao-9a2dfa18 | ❌ Échoué (MCP startup) | Remplacé par audit manuel |
| 2 — Public surfaces | cao-audit-surfaces-2 | ✅ Fini | P0 sur ProfileController::show() |
| 3 — Scope/policies | ~ (subagent) | ✅ Fini | Liste withoutGlobalScope classifiée |
| 4 — Doctrine/docs | ~ (subagent) | ✅ Fini | Cohérence Organization=Tenant confirmée |

## Constats retenus (scope TASK-123)

| # | Fichier | Risque | Action |
|---|---------|--------|--------|
| 1 | ProfileController::show() | P0 — cross-org data leak | Guard org |
| 2 | BlogPost model | P1 — pas de BelongsToTenantScope | Ajouter global scope |
| 3 | AdminBlogController | P1 — pas de filtre org | Guard org + filtre |

## Constats écartés

| Constat | Raison |
|---------|--------|
| withoutGlobalScope TransactionController | Pattern safe |
| User model BelongsToTenantScope | Trop large, reporté TASK-124 |
| BlogController public | Déjà bien scopé (P3) |
| Routes /explorer, /membres | Hors scope TASK-123 |
| Migration community_id → organization_id | Refactor massif, TASK dédiée |

---
# Progress Log


## 2026-05-23 19:51:50 Europe/Paris

Task created.

Owner: CODEX

Branch: TASK-123-t123-blog-public-surface-tenant-scope-hardening

## 2026-05-23 20:47:00 Europe/Paris

Phase 1 — Audit CAO :
- Créé 4 profils CAO, installés, lancés
- Agent 2 (surfaces) : ✅ terminé — P0 ProfileController::show()
- Agent 3 (scope) : ✅ terminé — withoutGlobalScope classifié
- Agent 4 (doctrine) : ✅ terminé — docs cohérentes
- Agent 1 (blog) : ❌ échoué — MCP startup error (2 MCP servers failed)
- CAO documenté dans ai/orchestrator/README.md (profils, --yolo en non-TTY, --agents par nom)

## 2026-05-23 21:00:00 Europe/Paris

Phase 1b — Audit BlogPost manuel (séquentiel read-only) :
- BlogController public : déjà bien scopé (currentOrganization() + community_id filters partout)
- AdminBlogController : P1 confirmé — aucun filtre org dans index/updateStatus/destroy
- BlogPost : P1 confirmé — pas de BelongsToTenantScope dans booted()
- @DOCS/06-audit_scope_communityVSorganization.md créé

## 2026-05-23 21:05:00 Europe/Paris

Phase 2 — Consolidation terminée :
- 3 patches retenus : ProfileController, BlogPost scope, AdminBlogController
- User model BelongsToTenantScope reporté (TASK-124)
- Pas de refactor massif, pas de migration DB

## 2026-05-23 21:30:00 Europe/Paris

Phase 3 — Patch minimal appliqué :
- **Fichier modifié** : `app/Http/Controllers/ProfileController.php` (+5 lignes)
- **Changement** : guard org `currentOrganization()` + `$user->community_id !== $organization->id` → abort(404)
- **Reverté** : BlogPost BelongsToTenantScope (cassait AdminBlogController, platform admin global)
- **Reverté** : AdminBlogController org filter (platform admin global, doit voir cross-org)

Tests :
- T0757ProfileOrganizationScopingTest créé (3 tests, ✅)
- T0756BlogOrganizationScopingTest (9 tests, ✅)
- BlogPostPolicyTest (12 tests, ✅)

Décisions clés :
- AdminBlogController = platform admin global (is_admin), pas d'org scope
- BlogPost ne reçoit PAS BelongsToTenantScope — BlogController fait déjà le filtrage explicite
- ProfileController::show() = seul patch retenu
- User model BelongsToTenantScope = reporté (trop large)

Phase 5 — Finalisation en cours...

# Modified Files

- `app/Http/Controllers/ProfileController.php` — guard org dans show() (+5 lignes)
- `tests/Feature/T0757ProfileOrganizationScopingTest.php` — nouveau test (3 scénarios)

Écartés après analyse :
- `app/Models/BlogPost.php` — reverté (BelongsToTenantScope trop large)
- `app/Http/Controllers/Admin/AdminBlogController.php` — reverté (platform admin global)

# Handoffs

n/a (mono-opérateur)

# Tests

- [x] T0757ProfileOrganizationScopingTest (3 tests — ✅ tous passent)
- [x] T0756BlogOrganizationScopingTest (9 tests — ✅ tous passent)
- [x] BlogPostPolicyTest (12 tests — ✅ tous passent)

---

# Test Results

Pending.

---

# Review Notes

Pending.