---
task_id: TASK-122
title: t122-default-organization-scope-resolution-audit-fix

status: MERGED

owner: CODEX

contributors:
  - CAO_WORKER_1
  - CAO_WORKER_2
  - CAO_WORKER_3

branch: TASK-122-t122-default-organization-scope-resolution-audit-fix

priority: HIGH

created_at: 2026-05-23 18:21:20 Europe/Paris
updated_at: 2026-05-23 18:28:00 Europe/Paris

labels:
  - tenant
  - organization
  - postgresql
  - data-audit

lock:
  status: UNLOCKED
  agent: CODEX
  since: 2026-05-23 18:28:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Fix Empty Public Pages in local PostgreSQL mirror:
after Organization = Tenant reinforcement, `/membres`, `/services`,
`/blog`, `/explorer` displayed empty results.

Root cause: production-imported records (19 users, 7 services, 4
service_requests, 1 blog_post, 2 transactions) had NULL community_id
and NULL organization_id. The resolver returned boucletest as default
org, but controllers filtered by community_id = boucletest.id, getting
zero results.

---

# CAO Worker Reports

## Worker 1 — Tenant Resolver Audit

- `ResolveUrlOrganization` middleware runs for all web requests
- Public feature routes (`/membres`, `/blog`, `/explorer`) go through
  `resolveDefaultOrganization()` with 3-tier fallback:
  1. static::$defaultOrganizationId (test only)
  2. Setting::get('default_organization_id') (not set)
  3. Community::where('is_active', true)->first() (returns boucletest)
- bindOrganization() sets current_organization and current_community
- BelongsToTenantScope filters by community_id, fail-closed with
  whereRaw('0 = 1') when no org resolved
- Controllers call currentOrganization(), manually filter by
  community_id on User, BlogPost queries

## Worker 2 — PostgreSQL Data Audit

- 24 users total: 19 with NULL community_id + 5 QA (CPME/BNI)
- 7 services: all NULL community_id
- 4 service_requests: all NULL community_id
- 1 blog_post: NULL community_id
- 2 transactions: all NULL community_id
- No organizations table exists (Organization = Community subclass)
- boucletest community is first active: is_public=true, is_active=true
- Setting 'default_organization_id' not configured

## Worker 3 — Tests & Regression Audit

- 30+ existing test files related to org scoping
- 3 patterns: WithTestOrganization trait, ResolveUrlOrganization
  static property, direct app()->instance() binding
- Missing: direct HTTP tests verifying default org resolution for
  guest access to public routes
- Proposed 7 tests covering /membres, /blog, /explorer, admin
  global, cross-org isolation

---

# Planned Actions

- [x] inspect architecture (CAO Worker 1)
- [x] inspect impacted files (CAO Worker 1, Worker 3)
- [x] inspect data distribution (CAO Worker 2)
- [x] implement LegacyDataOrganizationSeeder (backfill)
- [x] update pg-dump.sh prod-mirror flow
- [x] create T0752DefaultOrganizationResolutionTest
- [x] validate pages via browser
- [x] run test suite

---

# Progress Log

## 2026-05-23 18:21 — Task created, branch created

## 2026-05-23 18:22 — 3 x CAO workers launched in parallel

## 2026-05-23 18:26 — Consolidated CAO findings
Root cause: All 33 content records (users 19, services 7,
service_requests 4, blog_posts 1, transactions 2) have NULL
community_id. Default org resolves to boucletest but controllers
filter by community_id = boucletest.id → zero results.

## 2026-05-23 18:27 — Implemented fix
- Created LegacyDataOrganizationSeeder: backfills NULL community_id
  and organization_id on all 7 tables to the default public
  community, sets Setting::default_organization_id
- Updated pg-dump.sh prod-mirror: add Phase 4/5 backfill step
- Created 7 targeted tests in T0752DefaultOrganizationResolutionTest

## 2026-05-23 18:28 — Validation complete
- Run seeder: 33 records backfilled (19 users, 7 services, 4
  service_requests, 1 blog_post, 2 transactions)
- /membres: 19 members displayed, paginated, org-bound ✓
- /blog: 1 blog post displayed with categories & tags ✓
- /explorer: services listed, categories, search/filter UI ✓
- Idempotence: second seeder run = 0 records (no-op) ✓
- phpunit: 704/705 passed (1 pre-existing failure unrelated)
- 7 new T0752 tests: all pass (19 assertions)

---

# Handoffs

None.

---

# Tests

- [x] feature tests (phpunit: 704/705 passed)
- [x] browser validation (/membres, /blog, /explorer verified)
- [x] tenant validation (cross-org isolation test in T0752)

---

# Test Results

## T0752DefaultOrganizationResolutionTest
All 7 tests pass:
1. test_membres_returns_200_and_binds_org ✓
2. test_membres_shows_only_scoped_users ✓
3. test_explorer_returns_200_and_binds_org ✓
4. test_blog_index_returns_200_with_default_org ✓
5. test_blog_index_filters_by_resolved_org ✓
6. test_admin_dashboard_does_not_bind_org ✓
7. test_membres_does_not_show_users_from_other_org_after_rebind ✓

## Full Suite
704 passed, 1 failed (LoopActivityTrackingTest — pre-existing,
unrelated to this task, no org binding in setUp).

## Browser Validation
- /membres → 200, 19 members displayed, paginated
- /blog → 200, blog post displayed with categories/tags
- /explorer → 200, services listed with category filters

---

# Review Notes

## Root cause identified
Legacy production-imported records (pre-tenant architecture) have
NULL community_id/organization_id. The default org resolution works
correctly (returns boucletest), but controller queries filter by
community_id and find nothing.

## Fix rationale
A seeder-based data backfill is the minimal safe fix:
- No database migrations
- No global scope bypass
- No tenantless routes
- No cross-organization data leak
- Idempotent — safe to run multiple times
- Only affects NULL community_id records (does not touch QA/properly-scoped data)
- Uses existing public community (boucletest) as default
- Sets default_organization_id setting for consistent resolution

## Risks
- Legacy production users now show on default org members page
  (expected for a dev/mirror environment)
- After fresh prod-mirror, the seeder must run (now automated in
  pg-dump.sh prod-mirror flow)
- Production data backfill maps all unscoped records to a single
  default community (acceptable for dev/mirror; in production, proper
  user-to-community migration is separate work)

---

# Review Notes

Pending.