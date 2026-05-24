# Tenant Safety Workflow

## Goal

Preserve strict tenant isolation across the entire platform.

Tenant integrity is a critical architectural rule.

---

# Tenant Safety Rules

## Core Principle

A user must never access data from another community unless explicitly authorized.

---

# Verification Workflow

Before modifying tenant-related code:

1. inspect routes
2. inspect middleware
3. inspect policies
4. inspect scopes
5. inspect database queries
6. inspect Livewire behavior
7. inspect API responses

---

# Critical Components

Tenant-sensitive systems:

- User
- Service
- ServiceRequest
- Transaction
- Messaging
- Reviews
- Favorites

---

# Scope Rules

Tenant filtering should rely on:
- `BelongsToTenantScope`
- `ResolveCommunity`
- policies

Avoid:
- manual tenant filtering duplication
- bypassing scopes
- unsafe queries

---

# Dangerous Patterns

Avoid:

```php
withoutGlobalScopes()
```

unless explicitly justified and validated.

Avoid:

* raw queries without tenant validation
* manual joins bypassing scopes
* exposing foreign UUIDs

---

# Validation Rules

Always verify:

* authenticated user tenant
* route tenant slug
* policy tenant consistency
* database tenant consistency
* frontend tenant consistency

---

# API Safety

APIs must:

* respect tenant boundaries
* never expose cross-tenant data
* validate authenticated tenant access

---

# Characterization Gates (T139.2+)

Before any tenant/routing migration:

* **Smoke tests** must cover all critical routes before and after the change
  - Routes root-level: `/`, `/explorer`, `/membres`, `/blog`, `/boucles`, `/echanges`
  - Routes admin: `/admin/dashboard`, `/admin/users`, `/admin/services`, `/admin/requests`, `/admin/messages`
  - Routes community-prefixed: `/{community}/explorer`, `/{community}/dashboard`, `/{community}/membres`
* **Characterization tests** must freeze the current behavior before modifying it
  - Scope column used (community_id vs organization_id)
  - Fallback chain (current_organization → current_community)
  - Middleware bindings
  - Route parameter naming ({community} vs {organization})
* **Known-risk tests** (`@group tenant-known-risk`) must document deferred risks
  - Skipped by default, explicit activation required
* **Route-by-route proof** is mandatory to mark DONE
  - Never mark DONE without per-route validation evidence in TASK file
* **No silent regressions** — if a route was green before, it must remain green

# Tenant Resolution Binding Rules

## Binding Canonique

```php
app()->instance('current_organization', $organization);  // canonique
app()->instance('current_community', $organization);      // fallback legacy temporaire (T140.3)
```

Règles :
- `current_organization` est le binding canonique — toujours prioritaire
- `current_community` est un fallback legacy temporaire — à ne pas utiliser dans les nouveaux callers
- `CurrentOrganization::get()` résout dans cet ordre : `current_organization` → `current_community` → null
- Suppression de `current_community` interdite sans gates + plan de migration (voir T140.9)

## Interdictions

- Ne PAS introduire de nouveau `app('current_community')` dans les callers
- Ne PAS supprimer `current_community` sans avoir migré les 14 tests legacy dépendants
- Ne PAS supprimer `current_community` sans plan de retrait documenté

# Final Validation

Before merge:

* verify UI
* verify policies
* verify database consistency
* verify no cross-tenant leaks

