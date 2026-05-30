---
task_id: TASK-174
title: Remove community() aliases, ResolveCommunity middleware, and legacy community routes

status: MERGED

owner: SUPERVISOR

contributors: []

branch: TASK-174-remove-community-aliases-resolvecommunity-middleware-and-legacy-community-routes

priority: MEDIUM

created_at: 2026-05-30 14:09:44 Europe/Paris
updated_at: 2026-05-30 21:20:00 Europe/Paris

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
No violations (pre-existing issues in unrelated files — not from TASK-173/174)
```

---

## 2026-05-30 21:20:00 Europe/Paris

Commit created (merged TASK-173 + TASK-174 in one commit per Option C):

Hash: `8050271`
Message: `feat(organization): flatten Organization model, remove Community.php + community aliases/middleware/routes (TASK-173 + TASK-174)`

Stats: 33 files changed, 381 insertions(+), 336 deletions(-)
manifest.json intentionally excluded.

Pre-commit verification:
- rg "Community" in app/ source: only view names + CommunityRequest (valid model)
- rg "community_id" in app/ source: 0 results
- rg "ResolveCommunity" in app/ source: 0 results (only test characterization files)
- Tests: 692 passed, 1 pre-existing failure (T0757 — confirmed on clean develop)
- Pint: pre-existing issues only

---

# Review Notes

- TASK-173 + TASK-174 merged in single commit per Option C (ORCHESTRATOR validation)
- TASK-173 will be marked MERGED/SUPERSEDED
- T0757 failure is pre-existing (confirmed by testing on clean develop)
- public/build/manifest.json intentionally excluded from commit
- No UI regressions expected; only route names and middleware strings changed

---

## 2026-05-30 21:20:00 Europe/Paris

Merge TASK-174 into develop completed.

- Script: `ai/scripts/merge-task.sh TASK-174`
- Pre-merge check: PASSED (DONE, UNLOCKED, clean git)
- Fetch/Pull: develop up to date
- Merge: `git merge --no-ff` via ort strategy
- Merge commit: `343e5f2 Merge branch 'TASK-174-...' into develop`
- Files: 35 files changed, 497 insertions(+), 466 deletions(-)
- Version bump: skipped (bump-version.sh couldn't parse branch name — non-blocking)
- Push: pushed to origin/develop successfully
- Post-merge status: clean on develop

Status updated to MERGED.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
