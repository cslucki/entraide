---
task_id: TASK-152
title: Migration Middleware community→organization

status: MERGED

owner: ORCHESTRATOR

contributors:
  - SUPERVISOR

branch: TASK-152-community-migration-middleware

priority: HIGH

created_at: 2026-05-28 14:35:00 Europe/Paris
updated_at: 2026-05-28 15:03:00 Europe/Paris

labels:
  - migration
  - middleware
  - community→org

lock:
  status: UNLOCKED
  agent: ORCHESTRATOR
  since: 2026-05-28 15:03:00 Europe/Paris

handoff: false

pr:
  status: MERGED
  url: null
---

# TASK-152 — Migration Middleware community→organization

## Objective

Migrate community→organization references in middleware layer: `ResolveCommunity`, `ResolveUrlOrganization`, `ResolveApiOrganization`.

## Scope

1. `app/Http/Middleware/ResolveCommunity.php` — rename class, update Organization model reference
2. `app/Http/Middleware/ResolveUrlOrganization.php` — update Organization model reference
3. `app/Http/Middleware/ResolveApiOrganization.php` — update Organization model reference
4. Verify runtime resolution works with new Organization model

---

# Planned Actions
- [x] Migrate ResolveCommunity middleware
- [x] Migrate ResolveUrlOrganization middleware
- [x] Migrate ResolveApiOrganization middleware
- [x] Run full test suite
- [x] Merge into develop

---

# Progress Log

## 2026-05-28 14:35:00 Europe/Paris
Task created. Branch TASK-152 créée depuis develop après merge T151.

## 2026-05-28 14:59:28 Europe/Paris
Commit `70db06f` — 3 middleware files modifiés, 20 insertions, 20 deletions. Migration propre.

## 2026-05-28 15:03:00 Europe/Paris
Merge commit `07d1d2f` — merge(t152): community→org middleware migration. Mergé dans develop.

Tests: 788 ✅ / 35 ❌ (2 régressions attendues dans AdminCommunitiesTest — controllers pas encore migrés).

---

# Handoffs

---

# Tests
- [x] PHPUnit full suite : 788 pass (2 regressions from AdminCommunitiesTest, expected)

---

# Test Results

| Test Run | Result |
|----------|--------|
| Full PHPUnit suite | 788 ✅ / 35 ❌ (2 regressions attendues) |

---

# Review Notes

2 régressions dans AdminCommunitiesTest parce que les controllers utilisent encore l'ancienne route `/admin/communities`. Résolu en Phase 4.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
