---

task_id: TASK-058
title: TASK-058-organization-migration

status: IN_PROGRESS

owner: GLM

contributors: []

branch: TASK-058-task-058-organization-migration

priority: HIGH

created_at: 2026-05-11 21:08:30 Europe/Paris
updated_at: 2026-05-11 23:30:00 Europe/Paris

labels:

* architecture
* multi-tenant
* organization
* migration
* laravel
* mcp
* playwright

lock:
status: LOCKED
agent: CLAUDE
since: 2026-05-11 22:30:00 Europe/Paris

handoff: false

pr:
status: NOT_READY
url: null
---------

# Objective

Progressively migrate the platform from:

Community → Organization

while preserving:

* tenant isolation,
* SQLite compatibility,
* Playwright stability,
* MCP Laravel Boost compatibility,
* incremental migration safety.

This task introduces the first real Organization-native architecture layer.

References:

* 06-DOMAIN_ARCHITECTURE_V2.md
* 07-GLOSSARY.md
* 08-COMMUNITY_MIGRATION_STRATEGY.md
* 09-ORGANIZATION_MIGRATION_EXECUTION_PLAN.md
* CLAUDE.md
* AGENTS.md

---

# Migration Rules

Mandatory rules:

* Organization is now the official tenant concept.
* Community is considered legacy terminology.
* New code must prefer Organization naming whenever possible.
* Loops are collaborative contexts, NOT tenant boundaries.
* Migration must remain incremental and layer-based.
* Playwright must remain green during migration.
* SQLite compatibility must be preserved.
* MCP Laravel Boost tools should be preferred over blind shell exploration.

Strictly forbidden:

* giant search/replace
* uncontrolled refactors
* direct main branch pushes
* breaking tenant isolation
* massive database rewrites
* unrelated architecture refactors

---

# Planned Actions

## Phase 1 — Compatibility Layer

* [x] inspect current tenant architecture
* [x] inspect Community model usages
* [x] inspect middleware bindings
* [x] inspect route model bindings
* [x] inspect policies and scopes
* [x] create Organization compatibility model
* [x] add organization middleware alias
* [x] add current_organization compatibility context
* [x] prepare Organization route compatibility
* [x] add minimal PHPUnit coverage

---

## Phase 2 — Architecture Migration

* [ ] progressively introduce organization_id
* [x] migrate tenant scope to Organization-first resolution (BelongsToTenantScope)
* [ ] migrate models
* [ ] migrate middleware
* [ ] migrate routes
* [ ] migrate controllers
* [ ] migrate policies
* [ ] migrate Livewire components
* [ ] migrate UI terminology
* [ ] migrate tests
* [ ] migrate Playwright selectors

---

## Phase 3 — Stabilization

* [ ] validate tenant isolation
* [ ] validate messaging flows
* [ ] validate transaction flows
* [ ] validate admin flows
* [ ] validate responsive behavior
* [ ] inspect browser console
* [ ] inspect Livewire hydration
* [ ] validate SQLite compatibility

---

# Progress Log

## 2026-05-11 21:08:30 Europe/Paris

Task created.

Owner:
GLM

Branch:
TASK-058-task-058-organization-migration

Status:
IN_PROGRESS

Context:
Start of official Community → Organization migration.

Architecture target:

Platform
└── Organization
└── Loops
└── Members
└── Interactions

Important:

* Organization = tenant boundary
* Loop = collaborative context
* Loops are NOT tenant scopes

---

## 2026-05-11 22:30:00 Europe/Paris

Agent: CLAUDE

Phase 1 — Compatibility Layer — COMPLETED.

### Architecture Findings

Current tenant system:
- Community model → communities table (is_active, slug, admin_id, hero_*, accent_color, welcome_points)
- ResolveCommunity middleware → reads {community} slug, binds app('current_community') + View::share('currentCommunity')
- BelongsToTenantScope → reads app('current_community'), scopes by community_id
- Bootstrap alias: 'community' => ResolveCommunity::class
- Tables with community_id: users, services, service_requests, transactions, blog_posts, ai_interaction_logs

### Changes Made

1. app/Models/Organization.php (NEW)
   - Extends Community with explicit $table = 'communities'
   - Explicit $table is critical: without it, Eloquent derives 'organizations' from class name
   - Provides Organization type alias pointing to same DB table

2. database/factories/OrganizationFactory.php (NEW)
   - Extends CommunityFactory, overrides $model = Organization::class
   - Allows Organization::factory() in tests

3. app/Http/Middleware/ResolveCommunity.php (UPDATED)
   - Now binds both app('current_community') and app('current_organization')
   - Both point to the same Community instance
   - Also shares View::share('currentOrganization') alongside existing currentCommunity

4. bootstrap/app.php (UPDATED)
   - Added 'organization' => ResolveCommunity::class alias
   - The 'community' alias remains unchanged

5. tests/Feature/OrganizationCompatibilityTest.php (NEW)
   - 12 tests / 21 assertions — all pass
   - Covers: model inheritance, table binding, factory, slug resolution,
     middleware bindings, current_organization === current_community,
     alias registration

### Test Results

OrganizationCompatibilityTest: 12/12 passed
CommunityModelTest: 10/10 passed (no regression)

### Safety Assessment

- No DB changes
- No route changes
- No controller changes
- No Playwright impact
- SQLite compatible
- Existing current_community binding preserved

### Next Recommended Step

Phase 1 remaining: organization route compatibility (new routes using {organization} parameter).
Phase 2: progressively update BelongsToTenantScope to read app('current_organization') with fallback to app('current_community').

---

## 2026-05-11 23:00:00 Europe/Paris

Agent: CLAUDE

Phase 1 — Organization Route Compatibility — COMPLETED.

### Changes Made

1. app/Http/Middleware/ResolveCommunity.php (UPDATED)
   - Reads {organization} param as fallback to {community}:
     $slug = $request->route('community') ?? $request->route('organization');
   - Existing /{community}/* routes: completely unchanged ({community} wins via ??)
   - New /org/{organization} routes: middleware now resolves slug correctly

2. app/Models/Organization.php (UPDATED)
   - Added getRouteKeyName(): string returning 'slug'
   - Prepares Organization for future Eloquent route model binding (later phase)
   - Community::getRouteKeyName() untouched (still returns 'id')

3. routes/web.php (UPDATED)
   - Added $organizationConstraint = $communityConstraint (same slug regex)
   - Added comment block showing future /org/{organization} route group pattern
   - No actual routes added — Playwright is unaffected

4. tests/Feature/OrganizationRouteCompatibilityTest.php (NEW)
   - 8 tests / 12 assertions — all pass
   - Covers: {organization} param middleware resolution, both current_* bindings,
     404 for unknown/inactive, {community} precedence when both params present,
     getRouteKeyName on Organization and Community

### Test Results

OrganizationRouteCompatibilityTest: 8/8 passed
OrganizationCompatibilityTest: 12/12 passed (no regression)
CommunityModelTest: 10/10 passed (no regression)

### Deferred to Later Phase

- Implicit Eloquent route model binding (type-hinted Organization in controllers)
- URL generation via route() helper with Organization models
- These belong to the phase where /org/{organization} routes are officially introduced


### Route compatibility findings

Implicit Organization route model binding was intentionally deferred.

Reason:
- current architecture still relies on Community-native route resolution
- forcing implicit Organization binding now would increase migration complexity and risk
- compatibility layer remains middleware-driven for Phase 1

Future phases may introduce:
- explicit Organization route bindings
- RouteServiceProvider bindings
- Organization-native route groups

### Next Recommended Step

Phase 1 is now COMPLETE. All checkboxes ticked.
Next: Phase 2 entry — update BelongsToTenantScope to prefer current_organization
with current_community fallback, then migrate models one at a time.

---

## 2026-05-11 23:30:00 Europe/Paris

Agent: CLAUDE

Phase 2 — BelongsToTenantScope Organization-first resolution — COMPLETED.

### Change

app/Models/Scopes/BelongsToTenantScope.php

Old behavior:
- try { app('current_community') } catch → WHERE community_id = ?

New behavior:
- app()->bound('current_organization') → preferred (Organization-first)
- app()->bound('current_community')    → fallback (legacy compatibility)
- neither bound                        → no scope applied (identical to before)

Key decisions:
- app()->bound() replaces try/catch: explicit, no exception overhead
- Private resolveOrganization() method encapsulates the precedence logic cleanly
- WHERE clause still uses community_id (DB column unchanged — no migration needed)
- Variable renamed to $organization internally but behavior identical

### Runtime Behavior

With ResolveCommunity middleware (Phase 1): both current_organization and
current_community are bound to the same Community instance.
→ current_organization wins, same ID used, zero behavior change in production.

Legacy code (console, jobs, tests) that binds only current_community:
→ falls back to current_community, fully compatible.

Code that binds neither (non-tenant routes, admin, tests without binding):
→ no scope, all rows visible — identical to previous behavior.

### Impacted Models

- Service (booted: addGlobalScope(BelongsToTenantScope))
- ServiceRequest (booted: addGlobalScope(BelongsToTenantScope))
- Transaction (booted: addGlobalScope(BelongsToTenantScope))

withoutGlobalScope(BelongsToTenantScope::class) bypass: still works (tested).

### Test Results

BelongsToTenantScopeTest: 8/8 passed
Full regression (81 tests): 81/81 passed — zero regressions

Covers: organization precedence, community fallback, no binding = no scope,
all three scoped models, withoutGlobalScope bypass, Organization instance works.

### Remaining Migration Risks

- Livewire Explorer reads app('current_community') directly (not via scope)
  → candidate for Phase 2 model migration
- RegisteredUserController reads app('current_community') directly
  → candidate for Phase 2 controller migration
- HomeController reads current_community directly
  → candidate for Phase 2 controller migration
- community_id DB column still named community_id (Phase 5 database migration)

---

# Handoffs

None.

---

# Tests

* [x] feature tests — OrganizationCompatibilityTest (12) + OrganizationRouteCompatibilityTest (8) + BelongsToTenantScopeTest (8)
* [ ] browser validation
* [ ] responsive validation
* [ ] console inspection
* [ ] tenant validation
* [ ] transaction validation
* [ ] messaging validation
* [x] middleware validation — alias registration, current_organization binding, {organization} param resolution
* [ ] Playwright validation

---

# Test Results

Pending.

---

# Review Notes

Migration must remain:

* incremental,
* test-driven,
* Playwright-safe,
* SQLite-compatible,
* MCP-assisted.

Avoid architecture drift.
