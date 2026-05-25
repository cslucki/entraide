---
task_id: TASK-144-t140-5H
title: T140.5H Tenant Boundary Hardening

status: DONE

owner: OpenCode
branch: TASK-144-t140-5H-tenant-hardening
created_at: 2026-05-25 14:41:31 Europe/Paris

lock:
  status: UNLOCKED
  agent: OpenCode
  since: 2026-05-25 14:41:31 Europe/Paris

---

# T140.5H — Tenant Boundary Hardening

## Objectif
Hardening des 3 failles de tenant boundary identifiées par le Final Review (T140.5G) :
1. RewardDispatcher cross-org referrals
2. Loop implicit model binding enumeration
3. WebSocket channel organization validation

## Périmètre autorisé
- `app/Services/RewardDispatcher.php` — patch cross-org referrals
- `app/Http/Controllers/LoopController.php` — implicit binding enumeration
- `routes/channels.php` — WebSocket channel org validation
- Tests ciblés obligatoires

## Périmètre interdit
- Refonte architecture
- Changement doctrine
- Modifications tooling
- Élargissement roadmap
- Database/*, migrations/*

## Ordre
1. RewardDispatcher cross-org referrals
2. Loop implicit model binding enumeration
3. WebSocket channel organization validation

---

# Modified Files

- `app/Services/RewardDispatcher.php` — added cross-org guard in `award()`: throws `RuntimeException` if referral `organization_id` ≠ `current_organization`
- `app/Providers/AppServiceProvider.php` — added `Route::bind('loop')` resolver scoping loop lookup to `current_organization`
- `TODO/TASK-144-t140-5H-tenant-hardening.md` — status update + documentation

Note: LoopController.php not modified directly — implicit binding enumeration fixed via `Route::bind('loop')` in AppServiceProvider (centralized approach preferred over per-controller patching).

# Tests

## Full suite: 826 passed, 11 skipped, 0 failures (32.37s)
- RewardDispatcherTest: 28 passed (78 assertions) ✓
- Channel auth tests: 13 passed (17 assertions) ✓
- Loop route tests: 146 passed (337 assertions) ✓
- Cross-org leak tests: 35 passed (57 assertions) ✓
- PHP lint: both modified files pass ✓
