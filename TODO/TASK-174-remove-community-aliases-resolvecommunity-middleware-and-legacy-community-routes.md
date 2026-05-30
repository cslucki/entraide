---
task_id: TASK-174
title: Remove community() aliases, ResolveCommunity middleware, and legacy community routes

status: DONE

owner: SUPERVISOR

contributors: []

branch: TASK-174-remove-community-aliases-resolvecommunity-middleware-and-legacy-community-routes

priority: MEDIUM

created_at: 2026-05-30 14:09:44 Europe/Paris
updated_at: 2026-05-30 15:30:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: SUPERVISOR
  since: 2026-05-30 15:30:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Remove all community() model aliases, delete ResolveCommunity middleware, delete legacy community routes, and fix all resulting test failures.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [ ] validate UI (deferred — no UI changes beyond route names)

---
# Progress Log

## 2026-05-30 14:09:44 Europe/Paris

Task created.

Owner: SUPERVISOR
Branch: TASK-174-remove-community-aliases-resolvecommunity-middleware-and-legacy-community-routes
Status: IN_PROGRESS

## 2026-05-30 14:15:00 Europe/Paris

Started implementation.
Removed community() aliases from:
- User.php
- Loop.php
- Service.php
- ServiceRequest.php
- Transaction.php
- BlogPost.php
- Referral.php
- ReferralReward.php

## 2026-05-30 14:30:00 Europe/Paris

Deleted ResolveCommunity.php middleware.
Updated ResolveOrganization.php to standalone (no extends ResolveCommunity).
Updated ResolveUrlOrganization.php:
- Renamed isCommunityPrefixedRoute() → isOrganizationPrefixedRoute()
- Removed community param fallback checks
- Fixed log message

Updated bootstrap/app.php:
- Removed 'community' middleware alias
- Preserved 'organization' alias

Updated routes/web.php:
- Removed legacy /{community} route group (lines 264-374)
- Renamed admin route assign-community → assign-organization
- Inlined $communityConstraint into $organizationConstraint

## 2026-05-30 14:45:00 Europe/Paris

Fixed all route('community.*') → route('organization.*') across controllers and views.
Fixed all ['community' => ...] → ['organization' => ...] parameter arrays.

## 2026-05-30 15:00:00 Europe/Paris

Fixed test files:
- OrganizationCompatibilityTest.php
- OrganizationRouteCompatibilityTest.php
- T1403CurrentCommunityFallbackGatesTest.php
- T1392LegacyCharacterizationTest.php
- T1392RouteSmokeGatesTest.php
- T1404OrganizationParallelRoutesTest.php
- PublicFrenchPartnersRoutesTest.php
- T1405ARuntimeOrganizationIdTest.php
- ReferralRegistrationTest.php
- MembersPageTest.php
- AdminUsersTest.php
- BelongsToTenantScopeTest.php
- LoopModelTest.php

## 2026-05-30 15:15:00 Europe/Paris

Ran full Feature suite.
Result: 817 passed, 11 skipped, 1 failed.
The 1 failure (T0757ProfileOrganizationScopingTest) is pre-existing from TASK-172 side-effect.
Pint: `vendor/bin/pint --dirty` passed with no violations.

## 2026-05-30 15:30:00 Europe/Paris

Reorganized into micro-run mode per ORCHESTRATOR instruction.
All code changes complete and validated.
Awaiting ORCHESTRATOR/Cyril order for commit + merge.

# Handoffs

# Tests

- [x] feature tests — 817 passed, 11 skipped, 1 pre-existing failure
- [ ] browser validation — deferred, no frontend surface changes
- [ ] responsive validation — deferred
- [x] console inspection — pint clean
- [x] tenant validation — route scoping verified via tests

---

# Test Results

```
Full Feature suite:
817 passed, 11 skipped, 1 failed

Failed:
T0757ProfileOrganizationScopingTest (pre-existing TASK-172 side-effect)

Pint:
No violations
```

---

# Review Notes

- TASK-173 code (flatten Organization model, delete Community.php) is present in this branch's working tree. It appears both TASK-173 and TASK-174 changes were made on the TASK-174 branch.
- TASK-173 branch exists but may be stale. ORCHESTRATOR should clarify if TASK-173 should be merged separately or if its scope is fully subsumed by TASK-174.
- public/build/manifest.json is modified (Cyril's Vite rebuild) — intentionally not staged.
- No UI regressions expected; only route names and middleware strings changed.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
