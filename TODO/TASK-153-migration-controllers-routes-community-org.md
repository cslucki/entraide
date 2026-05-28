---
task_id: TASK-153
title: Migration Controllers + Routes community→organization

status: MERGED

owner: ORCHESTRATOR

contributors:
  - SUPERVISOR

branch: TASK-153-community-migration-controllers

priority: HIGH

created_at: 2026-05-28 15:10:00 Europe/Paris
updated_at: 2026-05-28 19:03:00 Europe/Paris

labels:
  - migration
  - controllers
  - routes
  - community→org

lock:
  status: UNLOCKED
  agent: ORCHESTRATOR
  since: 2026-05-28 19:03:00 Europe/Paris

handoff: false

pr:
  status: MERGED
  url: null
---

# TASK-153 — Migration Controllers + Routes community→organization

## Objective

Migrate all controller and route references from Community to Organization.

## Scope

### Fichiers renommés (4)
- `AdminCommunityController.php` → `AdminOrganizationController.php`
- `AdminMetaCommunityController.php` → `AdminMetaOrganizationController.php`
- `CommunityLandingController.php` → `OrganizationLandingController.php`
- `CommunityRequestController.php` → `OrganizationRequestController.php`

### Controllers modifiés (7)
- `LoopController.php` : `resolveCommunity()` → `resolveOrganization()`, removed community_id fallbacks
- `PointController.php` : `$user->community` → `$user->organization`
- `DashboardController.php` : same pattern
- `RegisteredUserController.php` : `community_id` → `organization_id`
- `AuthenticatedSessionController.php` : same pattern
- `AdminMessageController.php`, `AdminLoopController.php` : removed `?? $user->community_id`

### Routes
- `routes/web.php` : imports, route names `admin.communities.*` → `admin.organizations.*`, URLs `/communities` → `/organizations`
- `routes/channels.php` : removed community_id fallback

### AppServiceProvider
- Removed `auth()->user()?->community_id` fallback

---

# Planned Actions
- [x] Rename 4 controller files
- [x] Modify 7 controllers
- [x] Update routes/web.php
- [x] Update routes/channels.php
- [x] Update AppServiceProvider
- [x] Update view references (admin/communities/*)
- [x] Run test suite
- [x] Merge into develop

---

# Progress Log

## 2026-05-28 15:10:00 Europe/Paris
Task created. Branch TASK-153 créée depuis develop après merge T152.

## 2026-05-28 19:02:49 Europe/Paris
Commit `5b28975` — 22 files modifiés, 229 insertions, 230 deletions. Tous les controllers, routes, et vues associées migrés.

AdminCommunitiesTest : 18/18 PASS ✅ (les 2 regressions P3 sont résolues).

## 2026-05-28 19:03:00 Europe/Paris
Merge commit `59bfbff` — merge(t153): community→org controllers/routes migration. Mergé dans develop.

---

# Handoffs

---

# Tests
- [x] AdminCommunitiesTest : 18/18 PASS
- [x] Full suite : 788+ ✅ (pré-existants OOM non liés)

---

# Test Results

| Test Run | Result |
|----------|--------|
| AdminCommunitiesTest | 18/18 ✅ |
| Full PHPUnit | 788+ ✅ |

---

# Review Notes

Les 2 régressions de P3 sont résolues par P4 (routes `/admin/communities` → `/admin/organizations`).

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
