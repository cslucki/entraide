---
task_id: TASK-144-5A
title: T140.5A — Channels broadcast + ResolveApiOrganization → organization_id

status: IN_PROGRESS

owner: TECH_WRITER (OpenCode)

contributors:
  - PROJECT_SUPERVISOR
  - REVIEW_SUPERVISOR

branch: TASK-140-5A-channels-resolve-api-organization

priority: HIGH

created_at: 2026-05-25 08:30:00 Europe/Paris
updated_at: 2026-05-25 08:30:00 Europe/Paris

labels:
  - organization
  - migration
  - runtime
  - broadcast
  - middleware

lock:
  status: LOCKED
  agent: TECH_WRITER
  since: 2026-05-25 08:30:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# T140.5A — Channels broadcast + ResolveApiOrganization → organization_id

## Statut

IN_PROGRESS

## Objectif

Migrer les usages runtime de `community_id` vers `organization_id` dans :
1. `routes/channels.php` — broadcast channel auth closure
2. `app/Http/Middleware/ResolveApiOrganization.php` — middleware tenant resolution

## Périmètre strict

- **Autorisé :** channels.php, ResolveApiOrganization, tests associés, known-risks, audit doc.
- **Interdit :** LoopService, LoopMessageService, ReferralService, RewardDispatcher, controllers, Livewire, routes, DB.

## Progress

2026-05-25 08:30:00 — Branche créée, TASK file init.

## Modified Files

<!-- à remplir -->

## Tests

<!-- à remplir -->
