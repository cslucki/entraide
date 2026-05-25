---
task_id: TASK-144-t140-5E-lotD
title: T140.5E Lot D — ResolveUrlOrganization middleware

status: DONE

owner: OpenCode
branch: TASK-144-t140-5E-lotD-middleware
created_at: 2026-05-25 14:41:31 Europe/Paris

lock:
  status: UNLOCKED
  agent: OpenCode
  since: 2026-05-25 14:41:31 Europe/Paris

---

# T140.5E Lot D — ResolveUrlOrganization middleware

## Objectif
Migrer 2 `community_id` refs dans `app/Http/Middleware/ResolveUrlOrganization.php` →
`$user->organization_id ?? $user->community_id`.

## Périmètre
- `app/Http/Middleware/ResolveUrlOrganization.php`

## Interdit
- Autres middleware (ResolveCommunity, ResolveOrganization)
- Controllers, Livewire, admin, vues, models, DB

## Pre-flight
- Lines 232-233 : `if ($user->community_id) { return Community::find($user->community_id); }`
- Pattern : `$orgId = $user->organization_id ?? $user->community_id; if ($orgId) { return Community::find($orgId); }`

## Modified files
- `app/Http/Middleware/ResolveUrlOrganization.php` — 3 insertions, 2 deletions

## Tests
- `php artisan test` — 826 passed, 11 skipped (known risks), 0 failures
- `php -l` — no syntax errors
- `rg` — only 1 `community_id` remains as acceptable fallback on line 232

## Progress log
- 2026-05-25: Implemented change: `$user->community_id` → `$orgId = $user->organization_id ?? $user->community_id` with `if ($orgId)` guard
- 2026-05-25: All tests green (826/826)
- 2026-05-25: Status set to DONE
