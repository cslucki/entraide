---
task_id: TASK-157
status: DONE
owner: OpenCode
branch: TASK-157-cleanup-dead-community-id-services
lock:
  status: UNLOCKED
  agent: none
  since: null
---

# TASK-157 — Cleanup dead `community_id` fallbacks in Services

## Scope
Strict: remove 6 dead-code `?? $foo->community_id` fallbacks in 4 service files.

## Modified files
- `app/Services/LoopService.php` — 3 occurrences `?? $user->community_id` → removed
- `app/Services/LoopMessageService.php` — 1 occurrence `?? $sender->community_id` → removed
- `app/Services/ReferralService.php` — 1 occurrence `?? $referred->community_id` → removed
- `app/Services/RewardDispatcher.php` — 1 occurrence `?? $event->referrer->community_id` → removed

## Tests
- PHP syntax check: 4/4 pass
- Targeted test: `php artisan test --filter="LoopService|ReferralService|RewardDispatcher|LoopMessageService"`

## Review notes
Aucun changement fonctionnel. Les 6 lignes étaient dead code car `community_id` n'est plus une colonne DB — `$user->community_id` retourne `null` silencieusement. Le `??` operator passait donc toujours à la valeur suivante (`null` après suppression). Comportement inchangé.

## Progress log
2026-05-28 — Scope read, 6 occurrences identified, all fixed, syntax validated, committed.
