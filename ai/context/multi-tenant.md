# Étape 2 — Nouveau contenu complet pour `ai/context/multi-tenant.md`

````md id="obm9rd"
# Multi-Tenant Architecture

## Core Principle

BouclePro is an organization-native multi-tenant platform.

Official rule:

```text
Organization = Tenant
````

This is the canonical architecture.

Everything related to:

* security
* isolation
* ownership
* governance
* permissions
* data boundaries

must be scoped at the Organization level.

---

# Important Distinction

## Organization ≠ Loop

This distinction is critical.

### Organization

An Organization represents:

* the tenant boundary
* the security boundary
* the billing boundary
* the governance boundary
* the ownership boundary

Organizations isolate:

* users
* services
* transactions
* messaging
* workflows
* AI contexts

---

## Loop

A Loop represents:

* a collaborative context
* a subgroup
* a thematic space
* an operational environment

Loops are NOT:

* tenant boundaries
* security scopes
* database isolation layers

Loops exist INSIDE Organizations.

---

# Current Compatibility State

The current Laravel implementation still partially uses legacy terminology:

```text
Community
community_id
ResolveCommunity
current_community
```

This is intentional during migration phases.

Internally:

```text
Community ≈ Organization
```

but conceptually:

```text
Organization = official architecture
Community = temporary compatibility layer
```

---

# Current Tenant Resolution

## Runtime Resolution Priority

Current canonical runtime resolution:

```php
$organization = app()->bound('current_organization')
    ? app('current_organization')
    : (app()->bound('current_community')
        ? app('current_community')
        : null);
```

Rules:

* prefer `current_organization`
* preserve `current_community` fallback
* never break compatibility abruptly

---

# Middleware Architecture

## Current Middleware

Current compatibility middleware:

```text
ResolveCommunity
```

Responsibilities:

* resolve tenant from route
* bind current tenant
* share tenant with views
* enforce tenant existence

Currently binds:

* current_community
* current_organization

Both currently point to the same underlying model instance.

---

## Future Middleware

Future architecture may introduce:

```text
ResolveOrganization
```

but only when:

* routes are migrated
* tests are stable
* Playwright is validated
* compatibility is guaranteed

---

# Route Architecture

## Current Route Pattern

Current production-safe routes:

```text
/{community}/dashboard
/{community}/services
/{community}/messages
```

These remain canonical during compatibility phases.

---

## Future Route Pattern

Future organization-native routes may include:

```text
/org/{organization}/dashboard
/org/{organization}/services
/org/{organization}/messages
```

Rules:

* additive migration only
* never break existing routes abruptly
* dual support may temporarily coexist

---

# Database Architecture

## Current State

Current canonical database column:

```text
community_id
```

Used in:

* users
* services
* service_requests
* transactions
* blog_posts
* logs

This remains stable during compatibility phases.

---

## Future State

Future architecture may progressively introduce:

```text
organization_id
```

Migration rules:

* additive first
* dual-write possible
* no destructive migration first
* compatibility required
* SQLite compatibility mandatory

---

# Model Architecture

## Current Compatibility Pattern

Current models may expose BOTH:

```php
community()
organization()
```

Example:

```php
public function organization(): BelongsTo
{
    return $this->belongsTo(
        Organization::class,
        'community_id'
    );
}
```

Important:

* explicit `'community_id'` is required
* otherwise Eloquent derives `organization_id`

---

# Tenant Isolation Rules

## Mandatory Rules

Tenant isolation must NEVER be bypassed accidentally.

All organization-scoped resources must remain isolated:

* services
* requests
* transactions
* messaging
* reviews
* AI contexts
* workflows

---

## Scope Rules

Current tenant scope:

```text
BelongsToTenantScope
```

Current behavior:

1. prefer `current_organization`
2. fallback to `current_community`
3. no binding = no scope

This behavior is intentional.

---

# Admin Isolation

Admin systems intentionally bypass tenant scope.

Admin controllers:

* may access all organizations
* may access all members
* may manage moderation globally

Rules:

* bypasses must remain explicit
* never bypass implicitly
* always validate permissions

---

# Livewire Considerations

Livewire components must:

* hydrate safely
* preserve organization context
* avoid leaking tenant data

Current compatibility examples:

* `communityId` property names may temporarily remain
* underlying runtime resolution should prefer Organization

Avoid:

* storing large tenant state in Livewire
* mixing multiple organizations in the same component

---

# Blade & View Rules

Current compatibility state:

* `$currentCommunity` still exists
* `$currentOrganization` may coexist

Rules:

* prefer Organization terminology in new views
* preserve backward compatibility
* avoid massive Blade rewrites

---

# Playwright & QA Rules

Playwright stability is critical.

Rules:

* existing community routes must remain stable
* selectors should not break unnecessarily
* dual-route coverage may be introduced later
* cross-organization isolation must be tested

Critical QA domains:

* authentication
* transactions
* messaging
* permissions
* isolation
* redirects

---

# AI System Isolation

AI systems must remain organization-scoped.

AI isolation applies to:

* prompts
* embeddings
* memory systems
* assistants
* workflows
* semantic search
* automation

Rules:

* never leak cross-organization memory
* never expose cross-organization context
* isolate AI state carefully

---

# Migration Philosophy

The migration strategy is:

```text
incremental
compatibility-first
test-driven
non-destructive
```

Avoid:

* giant rewrites
* breaking renames
* destructive schema changes
* uncontrolled refactors

Preferred strategy:

1. compatibility layer
2. dual naming
3. runtime migration
4. route migration
5. DB migration
6. cleanup phase

---

# Forbidden Anti-Patterns

Never:

* assume Loop = tenant
* remove community_id prematurely
* remove current_community abruptly
* break Playwright stability
* bypass tenant scope casually
* mix organization data accidentally

---

# Strategic Goal

Final target architecture:

```text
Platform
└── Organization (tenant)
    └── Loops
        └── Members
        └── Services
        └── Transactions
        └── AI Systems
```

The goal is:

* conceptual clarity
* safe migration
* operational stability
* long-term maintainability
* AI-native organizational architecture

```
```
