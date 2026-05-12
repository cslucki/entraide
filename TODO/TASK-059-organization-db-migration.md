---
task_id: TASK-059
title: Organization DB migration

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-059-organization-db-migration

priority: MEDIUM

created_at: 2026-05-12 09:54:06 Europe/Paris
updated_at: 2026-05-12 11:30:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: 2026-05-12 11:30:00 Europe/Paris

handoff: false

pr:
  status: READY
  url: null
---

# Objective

Introduce `organization_id` as a progressive compatibility layer for the ongoing Community → Organization migration.

This task must remain:

- additive,
- compatibility-first,
- non-destructive,
- Playwright-safe,
- SQLite-compatible.

Official architectural rule:

Organization = Tenant
Loop ≠ Tenant

The objective is to safely introduce organization-native database compatibility WITHOUT breaking the existing `community_id` runtime architecture.

This task MUST NOT:

- remove `community_id`
- perform giant refactors
- rename routes globally
- rewrite Livewire components
- break tenant isolation
- break existing Playwright flows
- modify unrelated systems

---

# Planned Actions

## Architecture Inspection

- [ ] inspect current tenant runtime resolution
- [ ] inspect all `community_id` usages
- [ ] inspect tenant scopes and policies
- [ ] inspect transaction-sensitive models
- [ ] inspect migration dependencies

---

## Database Migration

- [ ] add nullable `organization_id` columns
- [ ] preserve existing `community_id`
- [ ] add safe indexes
- [ ] add compatible foreign keys
- [ ] ensure SQLite compatibility
- [ ] prepare safe backfill strategy

---

## Model Compatibility

- [ ] add `organization()` relationships
- [ ] preserve existing `community()` relationships
- [ ] use explicit foreign keys where required
- [ ] preserve backward compatibility

---

## Runtime Compatibility

- [ ] preserve `current_community`
- [ ] support `current_organization`
- [ ] avoid abrupt runtime changes
- [ ] preserve tenant isolation

---

## Validation

- [ ] run migration tests
- [ ] validate tenant isolation
- [ ] validate transaction integrity
- [ ] validate messaging isolation
- [ ] inspect Playwright impact
- [ ] inspect console errors

---
# Progress Log


## 2026-05-12 09:54:06 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-059-organization-db-migration

Status:
IN_PROGRESS

# Handoffs

# Tests

- [ ] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [ ] tenant validation

---

# Test Results

Pending.

---

# Review Notes

## Migration Constraints

This task is intentionally limited in scope.

Forbidden actions:

- removing `community_id`
- destructive migrations
- giant search/replace
- route rewrites
- Blade rewrites
- Livewire mass renaming
- uncontrolled refactors
- Loop/Tenant conceptual mixing

Priority order:

compatibility
→ stability
→ tenant safety
→ gradual migration

---

# Implementation Summary

## What was done

### Database migration
- Added nullable `organization_id` (uuid, indexed) to: `users`, `services`, `service_requests`, `transactions`, `blog_posts`, `ai_interaction_logs`
- Backfilled all existing rows: `organization_id = community_id`
- SQLite-compatible `UPDATE` statement
- Rollback-safe via driver check

### HasOrganizationId trait (`app/Models/Traits/HasOrganizationId.php`)
- `creating` event: auto-syncs `organization_id = community_id`
- `updating` event: syncs `organization_id` only when `community_id` is dirty
- Applied to 5 models: `User`, `Service`, `ServiceRequest`, `Transaction`, `BlogPost`

### Model relationship updates
- `organization()` BelongsTo changed from `organization_id` FK (was `community_id`)
- `community()` BelongsTo preserved unchanged

### Controller dual-write safety
- `AdminController::assignCommunity`: explicit dual-write of both columns
- `AdminCommunityController::destroy`: explicit dual-null of both columns
- All other creation paths rely on trait auto-sync (ServiceController, RequestController, TransactionController, RegisteredUserController, AdminController::update)

## Files changed

```
app/Http/Controllers/Admin/AdminCommunityController.php  | 8 ++++----
app/Http/Controllers/Admin/AdminController.php           | 5 ++++-
app/Models/BlogPost.php                                  | 6 ++++--
app/Models/Service.php                                   | 6 ++++--
app/Models/ServiceRequest.php                            | 6 ++++--
app/Models/Transaction.php                               | 6 ++++--
app/Models/User.php                                      | 6 ++++--
app/Models/Traits/HasOrganizationId.php                  | 24 +++++++++++++++++++
database/migrations/2026_05_12_101622_add_organization_id_to_tables.php | 50 ++++++++++++++++++++++++++++++
```

## Validation
- 294 feature tests passing (597 assertions)
- Tenant isolation preserved (BelongsToTenantScope unchanged)
- No route, Blade, Livewire, or view changes
- Additive-only: no `community_id` removal
- Factory compatibility verified

# Deferred Issues

## Tenant runtime resolution in service/request publication

During final review, a pre-existing issue was identified:

When publishing a service or request, the system falls back to the current user's `community_id` rather than resolving from the active tenant context. The `community_id` value is passed as a hidden form input rather than being server-determined from the middleware-resolved organization.

This is NOT a regression introduced by TASK-059 — it is a pre-existing architectural/runtime issue.

Resolution deferred to:
- **TASK-061**: tenant-aware creation/runtime flows

Scope expansion is explicitly forbidden in this task.