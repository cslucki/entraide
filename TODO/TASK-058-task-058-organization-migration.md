---

task_id: TASK-058
title: TASK-058-organization-migration

status: IN_PROGRESS

owner: GLM

contributors: []

branch: TASK-058-task-058-organization-migration

priority: HIGH

created_at: 2026-05-11 21:08:30 Europe/Paris
updated_at: 2026-05-11 22:30:00 Europe/Paris

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
* [ ] prepare Organization route compatibility
* [x] add minimal PHPUnit coverage

---

## Phase 2 — Architecture Migration

* [ ] progressively introduce organization_id
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

# Handoffs

None.

---

# Tests

* [x] feature tests — OrganizationCompatibilityTest (12 tests, all pass)
* [ ] browser validation
* [ ] responsive validation
* [ ] console inspection
* [ ] tenant validation
* [ ] transaction validation
* [ ] messaging validation
* [x] middleware validation — alias registration + current_organization binding
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
