---
task_id: TASK-144-t140-5A
title: T140.5A Channels + ResolveApiOrganization → organization_id-first

status: MERGED

owner: OpenCode

contributors:
  - OpenCode

branch: TASK-144-t140-5A-channels-resolve-api-organization

priority: MEDIUM

created_at: 2026-05-24 22:30:00 Europe/Paris
updated_at: 2026-05-25 14:41:31 Europe/Paris

labels:
  - organization
  - migration
  - runtime
  - channels
  - api
  - middleware

lock:
  status: UNLOCKED
  agent: ''
  since: ''

handoff: false

pr:
  status: NOT_READY
  url: null
---

# T140.5A — Channels + ResolveApiOrganization → organization_id-first

## Objectif

Basculer les broadcast channels et le middleware API en organization_id-first, avec community_id fallback documenté si nécessaire.

## Changements

1. `routes/channels.php:25` — `$loop->community_id !== $user->community_id` → `$loop->organization_id !== $user->organization_id`
2. `app/Http/Middleware/ResolveApiOrganization.php` — Resolver organization_id-first, bind current_community pour compatibilité legacy

## Interdits

- controllers web, services, Livewire
- referrals/rewards, auth, admin
- routes web hors channels
- database/*, migrations/*
- modèles, policies métier, VERSION

## Tests

### Périmètre tests
- php artisan test --filter=Channel
- php artisan test --filter=Broadcast
- php artisan test --filter=ResolveApiOrganization
- php artisan test --filter=T1405A

## Progress

2026-05-24 22:30 — Master plan créé.
2026-05-24 22:35 — TASK file renommé en TASK-144-t140-5A.
2026-05-24 23:00 — TECH_WRITER : patches channels.php + ResolveApiOrganization.php, tests T1405A, audit doc.

## Modified Files

- routes/channels.php
- app/Http/Middleware/ResolveApiOrganization.php
- tests/Feature/T1405ARuntimeOrganizationIdTest.php
- docs/audits/T140.5A-channels-resolve-api-organization.md

## Tests

### T1405A — 15 passed (22 assertions)
- 6 channel authorization
- 3 API middleware resolution
- 4 legacy compatibility
- 1 cross-org regression
- 1 middleware binding verification

### Tests externes
- php artisan test --filter=Channel — à exécuter
- php artisan test --filter=Broadcast — à exécuter
- php artisan test --filter=ResolveApiOrganization — à exécuter
