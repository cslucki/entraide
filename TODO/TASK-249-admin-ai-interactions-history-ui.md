---
task_id: TASK-249
title: Admin AI interactions history UI

status: DONE

owner: OPENCODE

contributors:
  - CODEUR

branch: TASK-249-admin-ai-interactions-history-ui

priority: HIGH

created_at: 2026-06-11 16:52:01 Europe/Paris
updated_at: 2026-06-11 17:15:00 Europe/Paris

labels:
  - ai
  - admin
  - ui
  - history

lock:
  status: UNLOCKED
  agent: null
  since: 2026-06-11 17:15:00 Europe/Paris

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

- [x] read mandatory docs (AGENTS.md, SMT skill, ai-local/README, tooling, task/conversation)
- [x] inspect existing admin patterns (controller, view, routes, sidebar)
- [x] create `AdminAiInteractionController` with index + show
- [x] add routes to `routes/web.php`
- [x] create list view `admin/ai-interactions/index.blade.php`
- [x] create detail view `admin/ai-interactions/show.blade.php`
- [x] add sidebar nav item in `admin.blade.php`
- [x] create feature test `AdminAiInteractionTest.php`
- [x] run targeted tests + regression
- [x] update TASK and conversation

---

# Progress Log

## 2026-06-11 16:52:01 Europe/Paris

Task created. Branch `TASK-249-admin-ai-interactions-history-ui`.

## 2026-06-11 16:52:30 Europe/Paris

ORCH created conversation file, committed TASK file, sent SMT to CODEUR.
Awaiting CODEUR DONE.

## 2026-06-11 17:15:00 Europe/Paris

CODEUR DONE report:

- `AdminAiInteractionController` created with index + show, filters (provider, scenario, status, date_from, date_to, search), pagination 25/page.
- Routes added: `admin.ai-interactions` (GET /admin/ai-interactions), `admin.ai-interactions.show` (GET /admin/ai-interactions/{interaction}).
- List view created with paginated table, filters, empty state.
- Detail view created with full record display + formatted JSON payload.
- Sidebar nav item `Historique IA` added in IA group after `Supervision IA`.
- Feature test `AdminAiInteractionTest.php` created with 13 tests covering access, pagination, filters, detail, sidebar.
- Date filter fixed in controller to use `where('created_at', '>=', ...)` instead of `whereDate`.
- `pint --dirty` passed (style fixes in controller and routes).
- All targeted tests green: AdminAiInteractionTest (13 passed, 38 assertions), AdminAiSupervisionTest regression (48 passed, 187 assertions).
- DB preflight confirmed safe (`bouclepro_test`).
- No forbidden files modified (no migration, config, provider, model, schema changes).

---

# Handoffs

## 2026-06-11 16:52:30 Europe/Paris — ORCH to CODEUR

SMT sent via tmux with full scope instructions. Conversation:
`ai-local/conversations/20260611-16h52-TASK-249-ai-interactions-history-ui.md`

---

# Tests

- [x] feature tests for list + detail + filters
- [x] regression: AdminAiSupervisionTest

---

# Test Results

2026-06-11 17:15 Europe/Paris

- `AdminAiInteractionTest`: 13 passed, 38 assertions
- `AdminAiSupervisionTest` regression: 48 passed, 187 assertions
- DB preflight: `database.default = pgsql`, `database.connections.pgsql.database = bouclepro_test` — safe.

---

# Review Notes

- VERIFICATOR must confirm scope strict: no supervision/provider/config/schema/model changes.
- VERIFICATOR must confirm sidebar active state on new routes.
- VERIFICATOR must confirm filters preserve GET params in pagination links.
- VERIFICATOR must confirm empty state message when no interactions exist.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
