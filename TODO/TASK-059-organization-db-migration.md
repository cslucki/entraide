---
task_id: TASK-059
title: Organization DB migration

status: IN_PROGRESS

owner: OPENCODE

contributors: []

branch: TASK-059-organization-db-migration

priority: MEDIUM

created_at: 2026-05-12 09:54:06 Europe/Paris
updated_at: 2026-05-12 09:54:06 Europe/Paris

labels: []

lock:
  status: LOCKED
  agent: OPENCODE
  since: 2026-05-12 09:54:06 Europe/Paris

handoff: false

pr:
  status: NOT_READY
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
--