---
task_id: TASK-202
status: DONE
owner: SUPERVISOR

contributors:
  - ORCHESTRATOR

lock: UNLOCKED

branch: TASK-202-drop-settings-table
---

# TASK-202: Drop settings table

## Objectif

Supprimer l'ancienne table `settings` (remplacée par `organization_settings` + `organizations.is_default`).

## Fichiers modifiés

- `database/migrations/2026_06_02_000040_drop_settings_table.php` — créé

## Tests

- `php artisan test` : 811 passed, 14 pre-existing Resend failures, 11 skipped
- Migration testée localement (SQLite) : OK

## Review

Verdict VERIFICATOR : ACCEPT

## Bloqueurs

Aucun
