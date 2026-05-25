---
task_id: TASK-144-t140-5E-lotC
title: T140.5E Lot C — Livewire Explorer + Views admin → organization_id

status: IN_PROGRESS

owner: OpenCode

contributors:
  - OpenCode

branch: TASK-144-t140-5E-lotC-livewire-views

priority: MEDIUM

created_at: 2026-05-25 21:15:00 Europe/Paris
updated_at: 2026-05-25 21:15:00 Europe/Paris

labels:
  - organization
  - livewire
  - views
  - T140.5E

lock:
  status: UNLOCKED
  agent: OpenCode
  since: 2026-05-25 21:15:00 Europe/Paris

---

# T140.5E Lot C — Livewire Explorer + Views admin → organization_id

## Objectif

Migrer les refs `community_id` dans Explorer Livewire et les 2 vues admin users.

## Périmètre autorisé

- `app/Http/Livewire/Explorer.php` — 2 refs
- `resources/views/admin/users/edit.blade.php` — 2 refs
- `resources/views/admin/users.blade.php` — 2 refs

## Interdit

- Admin controllers
- Autres Livewire components
- Models/database/migrations
- Middleware

---

## Modified Files

<!-- à remplir -->

## Tests

<!-- à remplir -->
