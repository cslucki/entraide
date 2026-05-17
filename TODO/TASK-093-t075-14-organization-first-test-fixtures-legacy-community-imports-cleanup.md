---
task_id: TASK-093
title: 'T075.14 — Organization-First Test Fixtures & Legacy Community Imports Cleanup'

status: IN_PROGRESS

owner: OPENCODE

contributors: []

branch: TASK-093-t075-14-organization-first-test-fixtures-legacy-community-imports-cleanup

priority: MEDIUM

created_at: 2026-05-17 21:27:01 Europe/Paris
updated_at: 2026-05-17 21:27:01 Europe/Paris

labels:
  - organization-migration
  - test-fixtures
  - legacy-cleanup

lock:
  status: LOCKED
  agent: OPENCODE
  since: 2026-05-17 21:27:01 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Réduire les imports legacy `App\Models\Community` dans les tests PHPUnit et durcir les fixtures vers Organization-first.

---

# Scope

## Included (In-Scope)

- tests PHPUnit (audit des imports `App\Models\Community`)
- factories / helpers test-only si strictement nécessaire
- TASK file (cette tâche)
- documentation courte uniquement dans le TASK file

## Excluded (Out-of-Scope)

- aucune migration DB
- aucune suppression de `App\Models\Community`
- aucun remplacement global (search/replace)
- aucun changement runtime large
- aucun contrôleur métier
- aucune API
- aucune Policy
- aucune route
- aucune UI
- aucun ChatLoop
- aucune nouvelle feature métier
- aucune modification PROD

---

# Architecture Rules

- Organization = Tenant
- Loop ≠ Tenant
- Partner ≠ Tenant
- `current_organization` est la source runtime canonique
- `organization_id` est canonique côté code
- `community_id` reste uniquement une colonne DB legacy de transition
- ne pas introduire de nouveau `current_community`
- ne pas créer de nouveau `ResolveCommunity`
- ne pas nommer de nouveaux tests/helpers/services avec Community comme concept actif
- les nouveaux tests ou fixtures doivent être Organization-first
- les usages legacy restants doivent être documentés avec justification et handoff

---

# Planned Actions

1. Audit — lister tous les imports `App\Models\Community` dans `tests/`
2. Audit — lister les factories et helpers test liés à Organization/community_id
3. Analyse — identifier les tests pouvant passer à `App\Models\Organization`
4. Analyse — identifier les imports legacy devant rester temporairement
5. Adaptation — modifier uniquement les fixtures/helpers test-only nécessaires
6. Tests — ajouter ou adapter tests ciblés si nécessaire (Organization-first)
7. Validation — lancer tests ciblés
8. Validation — lancer full suite (SQLite + PostgreSQL)
9. Documentation — documenter résultats et restes legacy dans ce TASK file
10. Review — préparer review OPENAI
11. Finalisation — check-task.sh → finalize-task.sh → merge-task.sh

---
# Progress Log


## 2026-05-17 21:27:01 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-093-t075-14-organization-first-test-fixtures-legacy-community-imports-cleanup

Status:
IN_PROGRESS


## 2026-05-17 22:45:12 Europe/Paris

Implementation complete. 7 files modified, all imports Community → Organization, Organization::factory() used for tenant concept fixtures, community_id preserved only as DB legacy column where required.

### Files Modified

1. `tests/Concerns/WithTestOrganization.php` — Test trait using Organization instead of Community
2. `tests/Feature/CurrentOrganizationTest.php` — Organization replacements, tests runtime resolution
3. `tests/Feature/ReferralRegistrationTest.php` — Organization replacements + organization_id
4. `tests/Feature/ReferralServiceTest.php` — Organization replacements + organization_id
5. `tests/Feature/PointsSystemTest.php` — Organization replacements (sed + manual use statement fix)
6. `tests/Feature/BadgeServiceTest.php` — Organization replacements (sed + manual use statement fix)
7. `tests/Feature/RewardDispatcherTest.php` — Organization replacements (sed + manual use statement fix)

### Changes Applied

- `use App\Models\Community` → `use App\Models\Organization`
- `private Community $org` → `private Organization $org`
- `Community::factory()` → `Organization::factory()`
- `community_id` → `organization_id` in ReferralServiceTest/ReferralRegistrationTest (Referral has HasOrganizationId, organization_id is canonical)
- `community_id` PRESERVED in factories and DB assertions (User::factory, Service::factory, Referral assertions) — this is the legacy DB column, synchronized by HasOrganizationId trait

### Verification Complete

- No `AppModelsOrganization` broken imports
- No `App\Models\Community` imports in modified files
- No `Community::factory` in modified files
- `community_id` preserved only as DB column legacy where justified

### Test Results

**Targeted tests passed (83 tests, 192 assertions):**

- PointsSystemTest: 5 tests, 13 assertions (0.54s)
- BadgeServiceTest: 12 tests, 16 assertions (0.95s)
- RewardDispatcherTest: 28 tests, 78 assertions (1.10s)
- CurrentOrganizationTest: 11 tests, 13 assertions (0.64s)
- ReferralRegistrationTest: 3 tests, 11 assertions (0.81s)
- ReferralServiceTest: 9 tests, 24 assertions (1.04s)
- SearchControllerTest (WithTestOrganization): 9 tests, 21 assertions (0.75s)
- FavoriteControllerTest (WithTestOrganization): 3 tests, 6 assertions (0.12s)
- FullExchangeFlowTest (WithTestOrganization): 3 tests, 16 assertions (0.23s)

Total targeted: 83 tests, 192 assertions, 6.18s

### Legacy Community Imports Remaining

Testers audited (25 total files with use App\Models\Community):

**Safe replacements (DONE - 7 files):**
- WithTestOrganization.php
- CurrentOrganizationTest.php
- ReferralRegistrationTest.php
- ReferralServiceTest.php
- PointsSystemTest.php
- BadgeServiceTest.php
- RewardDispatcherTest.php

**Legacy tolerated temporarily (DEFERRED):**

**Loop tests (use App\Models\Community justified):**
- LoopModelTest.php — tests Loop model with loop.community_id DB column legacy
- LoopCreationTest.php — Loop with loop.community_id, legacy relations
- LoopMessageTest.php — Loop with loop.community_id
- LoopActivityTrackingTest.php — Loop with loop.community_id
- LoopMemberInvariantTest.php — Loop complex, multiple community_id relations

**Compatibility tests (explicitly test Community ↔ Organization):**
- CommunityModelTest.php — explicitly tests Community model
- OrganizationCompatibilityTest.php — explicitly tests compatibility
- OrganizationRouteCompatibilityTest.php — explicitly tests route-level compatibility
- BelongsToTenantScopeTest.php — tests legacy current_community fallback scope
- ResolveUrlOrganizationTest.php — tests URL resolution legacy

**Tenant safety tests (explicitly test community_id tampering):**
- T0755ServicesRequestsTenantSafetyTest.php — tenant safety with community_id
- T07411RoutesTenantSafetyTest.php — tenant safety routes

**Model-specific tests (community_id is canonical DB column):**
- ReferralTest.php — tests Referral model with community_id DB column
- T0756BlogOrganizationScopingTest.php — Blog scoping with community_id assertions

**Admin/Policies/API tests (deferred to dedicated passes if needed):**
- Admin/* — admin tests with community_id legacy
- Policies/* — policy tests with community_id legacy
- Api/ServiceApiTest.php — API with community_id
- Api/TransactionApiTest.php — API with community_id

**Justification for deferral:**
- Loop model uses loop.community_id DB column legacy
- Compatibility tests explicitly test Community ↔ Organization
- Tenant safety tests explicitly test current_community fallback
- Policies/admin tests explicitly test isolation with community_id
- No business blockers, incremental migration only
- No DB migrations in T075.14

### No Runtime Files Touched

All changes are test-only:
- No controllers modified
- No models modified (only test imports)
- No policies modified
- No routes modified
- No UI modified
- No migrations
- PROD untouched
- main branch untouched

# Handoffs

# Tests

- [ ] tests ciblés (Organization-first fixtures)
- [ ] full suite PHPUnit (SQLite)
- [ ] full suite PHPUnit (PostgreSQL)
- [ ] tenant isolation validation
- [ ] pas de browser / UI validation (hors scope)

---

# Test Results

Pending.

---

# Review Notes

Pending.

---

# Predecessor

T075.13 — Runtime current_community Removal Pass (MERGED, commit ee280a7 on develop).
- TASK file: `TODO/TASK-092-t075-13-runtime-current-community-removal-pass.md`
- CI PostgreSQL: SUCCESS — run 26000184183

---