---
task_id: TASK-144
title: T140.5 Controllers / Services / API / Channels → organization_id

status: IN_PROGRESS

owner: CYRIL

contributors:
  - OpenCode

branch: TASK-144-t140-5-runtime-organization-id

priority: MEDIUM

created_at: 2026-05-24 22:20:00 Europe/Paris
updated_at: 2026-05-24 22:20:00 Europe/Paris

labels:
  - organization
  - migration
  - runtime
  - audit

lock:
  status: LOCKED
  agent: OpenCode
  since: 2026-05-24 22:20:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# T140.5 — Controllers / Services / API / Channels → organization_id

## Statut

TODO

## Objectif

Migrer les usages runtime restants de `community_id` vers `organization_id` dans :
- controllers
- services
- API tenant resolution
- broadcast channels
- Livewire si concerné

## Étapes

1. Sous-agents read-only A-F
2. Synthèse _temp/T140.5-pre-flight.md
3. STOP validation humaine
4. GO → patch
5. Tests
6. Audit doc
7. Commit

## Progress

2026-05-24 22:20:00 Europe/Paris — Branche créée, démarrage sous-agents A-F.

## Modified Files

<!-- à remplir -->

## Tests

<!-- à remplir -->
