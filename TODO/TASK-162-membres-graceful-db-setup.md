---
task_id: TASK-162
status: DONE
owner: OpenCode
branch: TASK-162-membres-graceful-db-setup
lock:
  status: UNLOCKED
  agent: none
  since: null
---

# TASK-162 — Generic setup-required state for known public business pages

## Scope
Show a clear setup-required page for known public business GET pages when DB has no Organization, instead of a raw 404.

## Design
Centralized middleware handling: `ResolveUrlOrganization` returns `members.setup-required` view for GET requests on routes in `$passthroughNoOrgRoutes` when no Organization can be resolved.

## Allowlist (`$passthroughNoOrgRoutes`)
- `explorer`, `membres`, `echanges`, `boucles`, `blog`, `search`

## Exclusions (keep existing behavior)
- Authenticated routes: `dashboard`, `transactions`, `messages`, `points`, `favorites`, `loops`, `reports` — unchanged
- POST/PUT/DELETE actions: unchanged (fail-closed)
- Unknown routes: remain 404

## Files modified
- `app/Http/Middleware/ResolveUrlOrganization.php` — centralized allowlist + setup page return for GET
- `app/Http/Controllers/HomeController.php` — removed abort(404) in members(), returns setup view
- `resources/views/members/setup-required.blade.php` — NEW: explanatory page (no secrets)
- `tests/Feature/MembersPageTest.php` — NEW: 6 tests (setup page, directory, public routes, unknown 404, auth routes, org exists)
- `tests/Feature/ResolveUrlOrganizationTest.php` — updated to expect setup page for known routes
- `tests/Feature/T0756BlogOrganizationScopingTest.php` — unchanged (blog POST still 404)

## Validation
- Targeted: 37 ✅
- Full PHPUnit: 831 ✅ / 0 ❌ / 11 ⏭️ (1764 assertions)

## Docs cited
- `ai/workflows/prod-local-sync.md`
- `ai/environment.md`
