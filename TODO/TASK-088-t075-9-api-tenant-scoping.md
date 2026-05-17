---
task_id: TASK-088
title: t075-9-api-tenant-scoping

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-088-t075-9-api-tenant-scoping

priority: MEDIUM

created_at: 2026-05-17 09:12:53 Europe/Paris
updated_at: 2026-05-17 11:00:32 Europe/Paris

labels:
  - api
  - tenant-isolation
  - organization

lock:
  status: UNLOCKED
  agent: null
  since: 2026-05-17 11:00:32 Europe/Paris

handoff: true

pr:
  status: NOT_READY
  url: null
---

# Objective

Implement T075.9 API tenant scoping so API routes resolve an Organization server-side and tenant-scoped Eloquent reads/writes no longer depend on test-only manual runtime binding.

Scope is limited to API tenant isolation. No business controller refactor, policy change, or BelongsToTenantScope change is part of T075.9.

---

# Planned Actions

- [x] inspect API routes and existing controller behavior
- [x] add API Organization resolution middleware
- [x] register middleware on the Laravel API group
- [x] add API tenant isolation tests
- [x] correct transaction isolation test to respect existing buyer/seller filtering
- [x] remove out-of-scope build manifest change
- [x] apply OPENAI review corrections for authenticated fail-safe resolution
- [x] run requested API tests, dirty Pint, and full test suite

---

# Endpoints Audited

- `POST /api/auth/register`
- `POST /api/auth/login`
- `GET /api/services`
- `GET /api/services/{service}`
- `GET /api/requests`
- `GET /api/requests/{serviceRequest}`
- `GET /api/users/{user}`
- `POST /api/auth/logout`
- `GET /api/profile`
- `PATCH /api/profile`
- `GET /api/transactions`
- `POST /api/transactions`
- `GET /api/transactions/{transaction}`
- `POST /api/transactions/{transaction}/approve`
- `POST /api/transactions/{transaction}/refuse`
- `POST /api/transactions/{transaction}/cancel`
- `POST /api/transactions/{transaction}/complete`
- `POST /api/transactions/{transaction}/confirm`

---

# ResolveApiOrganization Strategy

Implemented `App\Http\Middleware\ResolveApiOrganization` and appended it to the `api` middleware group.

Resolution order:

- Authenticated API request: resolve Organization from the authenticated user's legacy tenant FK column.
- Authenticated API request fail-safe: if the legacy tenant FK is null, missing, or points to an inactive Organization, return `403` JSON and never fall back to the default Organization.
- Public API request: resolve Organization from `Setting::get('default_organization_id')`.
- Fallback if no setting exists: first active Organization record.

Runtime binding:

- Binds `current_organization` only.
- Does not bind `current_community`.
- Uses `App\Models\Organization` in the middleware.

`X-Organization` decision:

- Removed from middleware behavior for T075.9.
- No existing API test or audited route requires public callers to select an arbitrary Organization by header.
- Public routes use the default Organization only until an explicit architecture decision introduces a safe API tenant selection mechanism.
- A regression test confirms `X-Organization` is ignored and cannot override the default Organization.

---

# Existing Transaction Behavior

`GET /api/transactions` behavior was inspected and preserved.

The endpoint returns paginated transactions where:

- the request is authenticated with Sanctum;
- the resolved Organization scope applies through existing tenant-scoped models;
- the authenticated user is either `buyer_id` or `seller_id`;
- results are ordered with `latest()`;
- pagination is `paginate(15)`, so JSON `total` is the total after Organization and buyer/seller filters.

The test now proves the intersection of Organization isolation and existing user participant filtering. It does not expect all transactions in the Organization.

---

# Files Modified

- `bootstrap/app.php`
- `app/Http/Middleware/ResolveApiOrganization.php`
- `tests/Feature/Api/AuthApiTest.php`
- `tests/Feature/Api/ApiTenantScopingTest.php`
- `tests/Feature/Api/TransactionApiTest.php`
- `TODO/TASK-088-t075-9-api-tenant-scoping.md`

Out of scope reverted:

- `public/build/manifest.json`

---

# Tests Added

`tests/Feature/Api/ApiTenantScopingTest.php` covers:

- public service index uses default Organization;
- `X-Organization` header cannot override default Organization;
- public service show rejects cross-Organization access;
- authenticated transaction index returns only buyer/seller transactions in the resolved Organization;
- authenticated token Organization wins over the default Organization;
- authenticated user without an Organization fails safe with `403` JSON;
- authenticated user with inactive Organization fails safe with `403` JSON;
- transaction store writes the server-resolved Organization into the legacy tenant FK column;
- public default Organization fallback with no user and no header;
- middleware binds `current_organization` for authenticated API requests;
- middleware does not bind the legacy runtime tenant key.

---

# Legacy Schema Usage

T075.9 keeps compatibility with the current schema only. No database migration is performed here.

Retained legacy DB column usages:

- `ResolveApiOrganization::resolveFromAuthenticatedUser()` reads `$user->community_id` as the current schema's Organization tenant FK.
- API tenant isolation tests create `Service`, `Transaction`, and `User` records with `community_id` to exercise the current tenant-scoped schema.
- Existing `TransactionController::store()` writes `transactions.community_id` from `current_organization`; this was inspected but not modified.

Clarifications:

- `community_id` is treated as a legacy DB column used as `organization_id` during transition.
- `community_id` remains only a legacy schema transition column in the new middleware/tests.
- No `current_community` runtime binding is introduced by T075.9.
- No new compatibility runtime layer is added for `current_community`.
- No new Community model import is introduced in `ResolveApiOrganization`; the middleware uses `App\Models\Organization`.

---

# Progress Log

## 2026-05-17 09:12:53 Europe/Paris

Task created.

Owner: OPENCODE

Branch: TASK-088-t075-9-api-tenant-scoping

Status: IN_PROGRESS

## 2026-05-17 09:39:33 Europe/Paris

Checkpoint correction after cockpit decisions:

- Reverted `public/build/manifest.json` only.
- Removed `current_community` binding from `ResolveApiOrganization`.
- Removed `X-Organization` tenant selection from middleware behavior.
- Corrected transaction isolation test to use one Sanctum token and respect existing buyer/seller filtering.
- Confirmed no controller, policy, or BelongsToTenantScope change is required.

## 2026-05-17 09:42:58 Europe/Paris

Validation completed:

- API tenant scoping focused tests pass.
- API-filtered feature tests pass.
- Dirty Pint completed and formatted only touched files.
- Full Laravel test suite passes.

## 2026-05-17 10:28:02 Europe/Paris

OPENAI review verdict: CHANGES REQUESTED.

Blocking issues addressed:

- Authenticated API requests no longer fall back to the default Organization when the authenticated user's legacy tenant FK is null, invalid, or inactive.
- `ResolveApiOrganization` now imports and queries `App\Models\Organization` instead of aliasing the legacy model as Organization terminology.
- `current_community` remains unbound by the new API middleware.
- `X-Organization` remains removed from middleware behavior.

Corrections applied:

- Authenticated requests resolve the user via Sanctum and bind only an active Organization from the user's legacy `community_id` column.
- Authenticated requests fail safe with `403` JSON when no valid active Organization can be resolved.
- Public requests with no authenticated user may still resolve the default active Organization or first active Organization fallback.
- Added regression tests for auth token Organization precedence over default, missing Organization fail-safe, and inactive Organization fail-safe.

Focused validation:

- `php artisan test --filter=ApiTenantScopingTest` — PASS, 11 tests / 21 assertions.

## 2026-05-17 10:30:46 Europe/Paris

Validation completed after OPENAI corrections:

- Adjusted existing API auth/transaction fixtures to provide a valid legacy tenant FK for authenticated requests, matching the new fail-safe API tenant invariant.
- `php artisan test --filter=ApiTenantScopingTest` — PASS, 11 tests / 21 assertions.
- `php artisan test --filter=Api` — PASS, 37 tests / 100 assertions.
- `./vendor/bin/pint --dirty` — PASS, formatted dirty PHP files.
- `php artisan test` — PASS, 637 tests / 1370 assertions.

## 2026-05-17 10:42:55 Europe/Paris

Final bounded validation before OPENAI re-review:

- Re-inspected `tests/Feature/Api/AuthApiTest.php` and `tests/Feature/Api/TransactionApiTest.php`.
- Confirmed existing API test changes are fixture-only: authenticated users now receive a valid legacy `community_id` DB tenant FK so business-behavior tests pass through the fail-safe API Organization middleware.
- Confirmed no business assertions were weakened, no transaction business logic was changed, and no middleware bypass was added as the primary proof in the new tenant scoping tests.
- Confirmed `ResolveApiOrganization` uses `App\Models\Organization`, binds only `current_organization`, and never falls back to default Organization for authenticated API requests.
- Confirmed public API requests without an authenticated user may use the default active Organization fallback only.

## 2026-05-17 11:00:32 Europe/Paris

OPENAI re-review verdict: APPROVE WITH NOTES.

Notes documented:
- `TransactionApiTest` retains a legacy `App\Models\Community` import. Acceptable for T075.9; deferred to T075.10 audit.
- Default Organization fallback on public read-only endpoints remains acceptable for T075.9 scope.

TASK-088 finalized:
- status: DONE
- lock: UNLOCKED
- No code changes in this finalization pass.
- No commit, push, or merge.

---

# Handoffs

## Handoff to T075.10 — Community Legacy Code Audit & Removal Plan

T075.10 should audit and plan progressive removal or replacement of legacy Community terminology and runtime compatibility code.

Recommended T075.10 scope:

- audit remaining `community_id` schema references and classify as DB migration, model compatibility, or runtime compatibility;
- audit remaining `current_community` runtime bindings/usages;
- plan migration path from legacy tenant FK naming to Organization-native naming;
- audit and plan removal of legacy `App\Models\Community` import in `tests/Feature/Api/TransactionApiTest.php`;
- audit whether the default Organization fallback on public read-only API endpoints should be hardened or documented;
- avoid DB migration execution without a dedicated task;
- keep tenant isolation stable while removing legacy compatibility incrementally.

---

# Tests

- [x] tenant validation
- [x] feature tests
- [x] full feature/API test sweep
- [x] full PHPUnit suite
- [x] OPENAI re-review APPROVE WITH NOTES
- [ ] browser validation not required for API-only backend change
- [ ] responsive validation not required for API-only backend change
- [ ] console inspection not required for API-only backend change

---

# Test Results

## 2026-05-17 09:39:33 Europe/Paris

- `php artisan test --filter=ApiTenantScopingTest` — PASS, 8 tests / 14 assertions.

## 2026-05-17 10:28:02 Europe/Paris

- `php artisan test --filter=ApiTenantScopingTest` — PASS, 11 tests / 21 assertions.

## 2026-05-17 10:30:46 Europe/Paris

- `php artisan test --filter=Api` — PASS, 37 tests / 100 assertions.
- `./vendor/bin/pint --dirty` — PASS, formatted dirty PHP files.
- `php artisan test` — PASS, 637 tests / 1370 assertions.

## 2026-05-17 10:42:55 Europe/Paris

- `php artisan test --filter=ApiTenantScopingTest` — PASS, 11 tests / 21 assertions.
- `php artisan test --filter=Api` — PASS, 37 tests / 100 assertions.
- `./vendor/bin/pint --dirty` — PASS, no remaining dirty formatting issues.
- `php artisan test` — PASS, 637 tests / 1370 assertions.

## 2026-05-17 11:00:32 Europe/Paris

- OPENAI re-review: APPROVE WITH NOTES.
- No additional code changes required.
- TASK-088 finalized: DONE / UNLOCKED.

---

# Review Notes

- No business controller was modified.
- No policy was modified.
- `BelongsToTenantScope` was not modified.
- Public API tenant selection by arbitrary `X-Organization` header was intentionally not introduced in T075.9.
- Existing `AuthApiTest` and `TransactionApiTest` updates are limited to valid legacy `community_id` fixture setup for authenticated users under the new API fail-safe invariant.

## OPENAI APPROVE WITH NOTES (2026-05-17 11:00:32 Europe/Paris)

Verdict: APPROVE WITH NOTES.

Confirmed by OPENAI review:
- `ResolveApiOrganization` uses `App\Models\Organization`.
- No `App\Models\Community as OrganizationModel`.
- No `current_community` runtime binding.
- Authenticated request: Organization resolved from `user.community_id` (legacy DB column); active Organization required; null/invalid/inactive → 403 JSON; no default Organization fallback.
- Public unauthenticated request: default Organization fallback accepted only for public read-only API routes.
- `X-Organization` not supported.
- `POST /api/transactions` writes `community_id` from server-resolved Organization.
- No business controller, Policy, `BelongsToTenantScope`, web route, migration, UI, or PROD modified.

OPENAI notes to track:
- `TransactionApiTest` retains legacy `App\Models\Community` import. Accepted for T075.9; deferred to T075.10.
- Default Organization fallback on public endpoints remains acceptable for T075.9 scope but must remain limited to public read-only routes.
