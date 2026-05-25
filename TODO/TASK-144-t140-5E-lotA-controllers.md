---
task_id: TASK-144-t140-5E-lotA
title: T140.5E Lot A — controllers métier → organization_id

status: IN_PROGRESS

owner: OpenCode

contributors:
  - OpenCode

branch: TASK-144-t140-5E-lotA-controllers

priority: MEDIUM

created_at: 2026-05-25 21:10:00 Europe/Paris
updated_at: 2026-05-25 21:10:00 Europe/Paris

labels:
  - organization
  - controllers
  - T140.5E

lock:
  status: UNLOCKED
  agent: OpenCode
  since: 2026-05-25 21:10:00 Europe/Paris

---

# T140.5E Lot A — Controllers métier → organization_id

## Objectif

Migrer les 39+ `community_id` refs dans les 9 controllers métier vers `organization_id`.

## Périmètre autorisé

- `app/Http/Controllers/BlogController.php` — 14 refs
- `app/Http/Controllers/TransactionController.php` — 6 refs
- `app/Http/Controllers/RequestController.php` — 3 refs
- `app/Http/Controllers/HomeController.php` — 5 refs
- `app/Http/Controllers/ServiceController.php` — 5 refs
- `app/Http/Controllers/ProfileController.php` — 2 refs
- `app/Http/Controllers/CommunityLandingController.php` — 1 ref
- `app/Http/Controllers/BlogCommentController.php` — 2 refs
- `app/Http/Controllers/Api/TransactionController.php` — 1 ref

Tests strictement nécessaires.

## Interdit

- Admin controllers
- Livewire
- Models
- Policies
- Database/migrations
- VERSION
- Middleware

---

## Modified Files

<!-- à remplir -->

## Tests

<!-- à remplir -->
