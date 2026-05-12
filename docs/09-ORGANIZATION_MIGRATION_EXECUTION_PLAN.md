````markdown
# 09-ORGANIZATION_MIGRATION_EXECUTION_PLAN

Status: ACTIVE MIGRATION PLAN

Purpose:
Define the real execution strategy for the migration:

Community → Organization

This document is operational.

It is intended for:
- Claude Code
- OpenCode
- MCP-assisted agents
- future contributors
- QA validation
- migration orchestration

References:
- 06-DOMAIN_ARCHITECTURE_V2.md
- 07-GLOSSARY.md
- 08-COMMUNITY_MIGRATION_STRATEGY.md
- CLAUDE.md
- AGENTS.md

---

# 1. Migration Goal

The objective is to migrate the platform from:

```text
Community-based architecture
````

toward:

```text
Organization-based architecture
```

while preserving:

* tenant isolation,
* QA stability,
* SQLite compatibility,
* Playwright compatibility,
* incremental deployment safety.

---

# 2. Core Migration Principle

The migration must be:

* incremental,
* layer-by-layer,
* test-driven,
* reversible when possible,
* architecture-safe.

The migration MUST NOT:

* rely on giant search/replace,
* perform uncontrolled rewrites,
* break tenant isolation,
* mix unrelated refactors.

---

# 3. Official Architectural Rules

## Tenant Boundary

```text
Organization = Tenant
```

NOT:

```text
Loop = Tenant
```

---

## Loop Definition

Loops are:

* collaborative contexts,
* relational spaces,
* organizational subgroups.

Loops are NOT:

* security boundaries,
* DB isolation layers,
* tenant scopes.

---

# 4. Migration Scope

## Included In T058

### Database

* organizations table
* organization_id migration
* foreign keys updates

### Backend

* Organization model
* middleware migration
* route migration
* policies migration
* services migration

### Frontend

* UI terminology updates
* Organization routes
* admin wording

### QA

* PHPUnit updates
* Playwright updates
* screenshots refresh
* tenant isolation validation

---

## Explicitly Excluded From T058

### Deferred Features

* Workspace architecture
* advanced RBAC
* federation system
* plugin marketplace
* multi-organization membership
* dynamic module loading
* AI orchestration layer
* self-hosted edition
* enterprise federation

These topics are postponed.

---

# 5. Migration Strategy

Migration will happen by architectural layers.

Mandatory order:

| Order | Layer       |
| ----- | ----------- |
| 1     | Database    |
| 2     | Models      |
| 3     | Middleware  |
| 4     | Routes      |
| 5     | Controllers |
| 6     | Policies    |
| 7     | Livewire    |
| 8     | Views       |
| 9     | PHPUnit     |
| 10    | Playwright  |

Do NOT skip layers.

---

# 6. Database Migration Plan

## Current Situation

Current tables:

```text
communities
community_id
```

Target:

```text
organizations
organization_id
```

---

## Migration Strategy

### Step 1

Create:

```text
organizations
```

table.

---

### Step 2

Duplicate existing community data into organizations.

---

### Step 3

Introduce:

```text
organization_id
```

alongside:

```text
community_id
```

temporarily.

---

### Step 4

Progressively migrate relationships.

---

### Step 5

Remove:

```text
community_id
```

ONLY after:

* tests pass,
* Playwright passes,
* routes are stable,
* policies are stable.

---

# 7. Model Migration Plan

## Current

```php
Community
```

---

## Target

```php
Organization
```

---

## Temporary Compatibility Allowed

Example:

```php
class Organization extends Community
{
}
```

Compatibility layers are acceptable temporarily.

---

# 8. Middleware Migration Plan

## Current

```text
ResolveCommunity
current_community
```

---

## Target

```text
ResolveOrganization
current_organization
```

---

## Important Rule

Tenant isolation must remain functional during all migration phases.

No temporary bypasses allowed.

---

# 9. Route Migration Plan

## Current Examples

```text
/community/{community}
```

or:

```text
/communities/{community}
```

---

## Target Examples

```text
/org/{organization}
```

---

## Important Rule

All new public URLs must use:

```text
Organization
```

terminology.

---

# 10. Controller Migration Plan

Controllers must progressively:

* replace Community references,
* use Organization resolution,
* preserve tenant safety,
* preserve policies.

Avoid giant controller rewrites.

---

# 11. Policy Migration Plan

Policies must be reviewed carefully.

Critical domains:

* transactions
* messaging
* requests
* services
* reviews
* admin access

Tenant isolation remains mandatory.

---

# 12. Livewire Migration Plan

Livewire components must:

* migrate progressively,
* preserve hydration stability,
* preserve URL synchronization,
* preserve tenant isolation.

Critical:

* Explorer
* Messaging
* Dashboards
* Admin panels

---

# 13. View Migration Plan

All visible terminology should migrate toward:

* Organization
* Loop
* Member

Avoid exposing:

* Community
* tenant
* workspace

in UI.

---

# 14. PHPUnit Migration Plan

Critical tests:

* tenant isolation
* policies
* transactions
* point ledger
* admin permissions
* messaging

Migration is NOT complete until tests pass.

---

# 15. Playwright Migration Plan

Playwright validation is mandatory.

Required validations:

* authentication
* organization isolation
* navigation
* messaging
* admin flows
* responsive behavior
* console errors
* Livewire stability

---

# 16. MCP Laravel Boost Rules

Agents MUST use Laravel Boost MCP tooling whenever possible.

Preferred tools:

* search-docs
* database-schema
* database-query
* browser-logs

Avoid blind shell exploration when MCP tools exist.

---

# 17. Git Strategy

## Branch

```text
TASK-058-organization-migration
```

---

## Commit Strategy

Prefer:

* small commits,
* isolated layers,
* explicit messages.

Example:

```text
refactor(organization): migrate middleware layer
```

---

# 18. Forbidden Actions

Strictly forbidden:

* giant search/replace
* direct main pushes
* uncontrolled DB rewrites
* tenant bypasses
* simultaneous unrelated refactors
* skipping Playwright validation
* skipping task updates

---

# 19. Risk Zones

## Critical Risk Areas

| Area                 | Risk     |
| -------------------- | -------- |
| Tenant scopes        | CRITICAL |
| Policies             | CRITICAL |
| Transactions         | CRITICAL |
| Messaging            | HIGH     |
| Livewire hydration   | HIGH     |
| Route model binding  | HIGH     |
| Playwright selectors | MEDIUM   |
| Admin UI             | MEDIUM   |

---

# 20. Migration Success Criteria

The migration is considered successful when:

* Organization is the official visible concept,
* tenant isolation remains stable,
* PHPUnit passes,
* Playwright passes,
* SQLite compatibility remains stable,
* no Community terminology remains in UI,
* MCP tooling remains operational,
* architecture remains understandable.

---

# 21. Final Principle

The goal is NOT:

* perfect architecture,
* over-engineering,
* future-proofing everything.

The goal is:

```text
A stable, understandable, organization-native V1
```

built with:

* safe migration,
* clean terminology,
* stable tenant isolation,
* maintainable Laravel architecture.

```
```
