---
task_id: TASK-250
title: Provider benchmark dataset

status: MERGED

owner: CODEUR

contributors:
  - CODEUR

branch: TASK-250-provider-benchmark-dataset

priority: HIGH

created_at: 2026-06-11 18:40:00 Europe/Paris
updated_at: 2026-06-11 18:48:00 Europe/Paris

labels:
  - ai
  - admin
  - benchmark
  - provider

lock:
  status: UNLOCKED
  agent: null
  since: 2026-06-11 18:48:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Create a standardised dataset of benchmark prompts stored in the database, used to compare AI provider cost/latency/quality.

# Planned Actions

- [x] read planning docs, AGENTS.md, SMT protocol, tooling
- [x] define scope: migration + model + seeder only (no benchmark runner, no dashboard, no CRUD UI)
- [x] create database migration for `admin_ai_benchmark_prompts` table
- [x] create `App\Models\AdminAiBenchmarkPrompt` model
- [x] create `database/seeders/AiBenchmarkPromptSeeder` with 13 representative prompts
- [x] create PHPUnit feature test
- [x] run tests (model + seeder + regression)
- [x] update TASK file
- [x] signal DONE to ORCHESTRATOR

# Progress Log

## 2026-06-11 18:40:00 Europe/Paris

Task created by ORCHESTRATOR. Scope validated by Cyril. Ready for CODEUR.

## 2026-06-11 18:48:00 Europe/Paris

CODEUR DONE report:

- Migration created: `database/migrations/2026_06_11_150001_create_admin_ai_benchmark_prompts_table.php`
- Model created: `app/Models/AdminAiBenchmarkPrompt.php` with `HasUuids`, casts, `active()` and `byCategory()` scopes
- Seeder created: `database/seeders/AiBenchmarkPromptSeeder.php` with 13 prompts covering 4 categories (clarification, supervision_content, review, technical)
- Tests created: `tests/Feature/Admin/AiBenchmarkPromptTest.php` with 6 tests
- DB preflight: `database.default = pgsql`, `database.connections.pgsql.database = bouclepro_test` — safe
- `AiBenchmarkPromptTest`: 6 passed, 16 assertions, 1.49s
- `AdminAiSupervisionTest` regression: 48 passed, 187 assertions, 4.23s
- No files outside scope modified
- Commit ready for push

# Handoffs

## 2026-06-11 18:48:00 Europe/Paris — CODEUR → ORCH

SMT sent via tmux. Conversation updated.

# Tests

- [x] model unit test
- [x] seeder test
- [x] full test suite regression (AdminAiSupervisionTest)

# Test Results

2026-06-11 18:48 Europe/Paris

- `AiBenchmarkPromptTest`: 6 passed, 16 assertions, 1.49s
- `AdminAiSupervisionTest` regression: 48 passed, 187 assertions, 4.23s
- DB preflight: `bouclepro_test` — safe

# Review Notes

- VERIFICATOR must confirm scope strict: no benchmark runner, no dashboard, no CRUD UI, no provider/config changes.
- VERIFICATOR must confirm only 4 new files created, no existing files modified.
- VERIFICATOR must confirm `AdminAiSupervisionTest` regression green.

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
