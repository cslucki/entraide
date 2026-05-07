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

# Final Validation

Before merge:

* verify UI
* verify policies
* verify database consistency
* verify no cross-tenant leaks

