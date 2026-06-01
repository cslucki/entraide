---
task_id: TASK-193
title: Fix member count inconsistency on homepage

status: MERGED

owner: ORCHESTRATOR

contributors:
  - SUPERVISOR

branch: TASK-193-fix-member-count-inconsistency-on-homepage

priority: MEDIUM

created_at: 2026-06-01 18:12:12 Europe/Paris
updated_at: 2026-06-01 18:12:12 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: 2026-06-01 18:35:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Bug 02 (Obsidian): compteur membres incohérent entre l'accueil (`/`) qui affiche `User::count()` (toutes les organisations) et l'annuaire (`/membres`) qui filtre par `organization_id`.

Fix: scoper le compteur de l'accueil avec `auth()->user()->organization_id`.

---

# Planned Actions

- [x] RAA: investigate sources (SUPERVISOR)
- [x] RAA: validated with correction (ORCHESTRATOR)
- [x] implement one-line fix in HomeController::index()
- [x] run tests
- [x] PR + merge (ORCHESTRATOR)

---
# Progress Log


## 2026-06-01 18:12:12 Europe/Paris

Task created.

Owner:
ORCHESTRATOR

Branch:
TASK-193-fix-member-count-inconsistency-on-homepage

Status:
IN_PROGRESS

## 2026-06-01 18:30:00 Europe/Paris

Implementation complete (SUPERVISOR).

- One-line fix applied: `User::count()` → scoped by `auth()->user()->organization_id`
- Tests: 826 passed, 11 skipped — zero regressions
- Handing off to ORCHESTRATOR for PR + merge

## 2026-06-01 18:35:00 Europe/Paris

CI green (PostgreSQL, 1m29s). Unlocked. Ready to merge.

# Handoffs

# Tests

- [x] feature tests

---

# Test Results

- PHPUnit: 826 passed, 11 skipped, 1756 assertions — zero regressions
- `php artisan test` exit code: 0

---

# Review Notes

One-line fix applied to `app/Http/Controllers/HomeController.php:18`:

```php
'users' => auth()->check()
    ? User::where('organization_id', auth()->user()->organization_id)->count()
    : User::count(),
```

Scopes homepage member count to authenticated user's organization, matching directory behavior. Unauthenticated visitors still see global count.

No route, middleware, view, or model changes. No risks.

---

# Handoffs

Handing off to ORCHESTRATOR for PR + merge.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`