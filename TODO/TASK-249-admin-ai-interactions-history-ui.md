---
task_id: TASK-249
title: Admin AI interactions history UI

status: IN_PROGRESS

owner: OPENCODE

contributors:
  - CODEUR

branch: TASK-249-admin-ai-interactions-history-ui

priority: HIGH

created_at: 2026-06-11 16:52:01 Europe/Paris
updated_at: 2026-06-11 16:52:01 Europe/Paris

labels:
  - ai
  - admin
  - ui
  - history

lock:
  status: LOCKED
  agent: OPENCODE
  since: 2026-06-11 16:52:01 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Create an admin UI to browse and view persisted admin AI interactions from the
`admin_ai_interactions` table (created in TASK-247). Pure read-only browsing:
no re-run, no edit, no delete.

Cyril/Cockpit confirmed TASK-249 scope and priority. Ollama runtime tested with
GPU RTX 4070 on WSL; default model `ministral-3:3b` for clarification will be
aligned in separate TASK-251.

---

# Context

- `admin_ai_interactions` table exists with UUID PK, org scope, telemetry
- `AdminAiInteraction` model exists with casts, relationships
- Admin sidebar IA group already has `Lab IA` + `Supervision IA`
- Admin pattern: inline table views, no shared table component
- Routes grouped under `auth` + `admin` middleware, prefix `/admin`

---

# Strict Scope

## Included

- **Controller**: `AdminAiInteractionController` (index + show)
- **Routes**: `GET /admin/ai-interactions` (list), `GET /admin/ai-interactions/{interaction}` (detail)
- **Route names**: `admin.ai-interactions`, `admin.ai-interactions.show`
- **List view**: paginated table (25/page), columns = date, scenario_id, provider, model, status, input_excerpt (truncated), latency_ms, cost_usd
- **Detail view**: full record display + formatted `result_payload` JSON + `result_summary`
- **Filters** (GET params): provider, scenario_id, status, date_from, date_to, search (input_excerpt LIKE)
- **Sort**: `created_at DESC`
- **Sidebar**: new item `Historique IA` in IA group, after Supervision IA
- **Tests**: feature test covering access, list rendering, pagination, filters, detail page

## Excluded (strictly forbidden)

- ❌ Modifications to `/admin/ai-supervision` page or controller
- ❌ `config/ai.php`, `.env`
- ❌ Providers (Ollama/OpenRouter/OpenAI)
- ❌ DB schema, migrations
- ❌ `AdminAiInteraction` model, `AdminAiInteractionPersistence`
- ❌ `LoggingSupervisionProvider`, `SupervisionProviderResolver`
- ❌ Destructive DB (`migrate:fresh`, `db:wipe`)
- ❌ OpenAI reactivation or config changes
- ❌ Re-run, edit, delete, export functionality

---

# Files

## New files
```
app/Http/Controllers/Admin/AdminAiInteractionController.php
resources/views/admin/ai-interactions/index.blade.php
resources/views/admin/ai-interactions/show.blade.php
tests/Feature/Admin/AdminAiInteractionTest.php
```

## Modified files
```
resources/views/layouts/admin.blade.php          (add sidebar nav item)
routes/web.php                                   (add 2 routes)
```

---

# Acceptance Criteria

1. List page accessible at `/admin/ai-interactions` under `auth` + `admin`
2. Table shows 25 interactions per page, newest first
3. Columns: date, scenario, provider, model, status, excerpt, latency, cost
4. Click row → detail page with all fields + formatted `result_payload` JSON
5. Filters work: provider, scenario, status, search, date_from, date_to
6. Filter reset when no params or explicit reset
7. Empty state message when no interactions exist
8. Sidebar link `Historique IA` active on these routes
9. All existing `AdminAiSupervisionTest` tests remain green
10. No migration/config/provider/model changes

---

# Risks

- Scope creep: dont add re-run, export, edit, delete
- Sidebar group active state must match new routes
- Pagination with filters (preserve GET params in links)

---

# Validation

- DB-safe preflight `bouclepro_test` before any test
- Targeted tests sequential only:
  - `tests/Feature/Admin/AdminAiInteractionTest.php`
  - `tests/Feature/Admin/AdminAiSupervisionTest.php` (regression)
- No parallel tests, no `--debug`, no `migrate:fresh`
- VERIFICATOR checks scope strict (no supervision/provider/config/schema changes)

---

# Planned Actions

- [ ] read mandatory docs (AGENTS.md, SMT skill, ai-local/README, tooling, task/conversation)
- [ ] inspect existing admin patterns (controller, view, routes, sidebar)
- [ ] create `AdminAiInteractionController` with index + show
- [ ] add routes to `routes/web.php`
- [ ] create list view `admin/ai-interactions/index.blade.php`
- [ ] create detail view `admin/ai-interactions/show.blade.php`
- [ ] add sidebar nav item in `admin.blade.php`
- [ ] create feature test `AdminAiInteractionTest.php`
- [ ] run targeted tests + regression
- [ ] update TASK and conversation

---

# Progress Log


## 2026-06-11 16:52:01 Europe/Paris

Task created. Branch `TASK-249-admin-ai-interactions-history-ui`.

---

# Handoffs

# Tests

- [ ] feature tests for list + detail + filters
- [ ] regression: AdminAiSupervisionTest

---

# Test Results

Pending.

---

# Review Notes

Pending.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
