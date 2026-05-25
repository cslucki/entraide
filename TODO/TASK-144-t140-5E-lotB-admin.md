---
task_id: TASK-144-t140-5E-lotB
title: T140.5E Lot B — Admin controllers community_id → organization_id

status: DONE

owner: OpenCode

contributors:
  - OpenCode

branch: TASK-144-t140-5E-lotB-admin

priority: MEDIUM

created_at: 2026-05-25 14:41:31 Europe/Paris
updated_at: 2026-05-25 14:41:31 Europe/Paris

labels:
  - organization
  - admin

lock:
  status: UNLOCKED
  agent: OpenCode
  since: 2026-05-25 14:41:31 Europe/Paris

---

# T140.5E Lot B — Admin controllers

## Objectif

Migrer les `community_id` refs dans les 4 controllers Admin vers `organization_id`.

## Périmètre autorisé

- `app/Http/Controllers/Admin/AdminController.php`
- `app/Http/Controllers/Admin/AdminCommunityController.php`
- `app/Http/Controllers/Admin/AdminMessageController.php`
- `app/Http/Controllers/Admin/AdminLoopController.php`

## Refusé

- Autres lots A/C/D/E
- Admin views (déjà Lot C)
- Models, database, migrations
- Policies, Livewire, middleware
