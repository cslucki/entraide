---
task_id: TASK-250
title: Provider benchmark dataset

status: IN_PROGRESS

owner: CODEUR

contributors:
  - CODEUR

branch: TASK-250-provider-benchmark-dataset

priority: HIGH

created_at: 2026-06-11 18:40:00 Europe/Paris
updated_at: 2026-06-11 18:40:00 Europe/Paris

labels:
  - ai
  - admin
  - benchmark
  - provider

lock:
  status: LOCKED
  agent: CODEUR
  since: 2026-06-11 18:40:00 Europe/Paris

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
- [ ] create database migration for `ai_benchmark_prompts` table
- [ ] create `App\Models\Ai\AiBenchmarkPrompt` model
- [ ] create `database/seeders/AiBenchmarkPromptSeeder` with 10–15 representative prompts
- [ ] create PHPUnit feature test
- [ ] run tests (model + seeder)
- [ ] update TASK file
- [ ] signal DONE to ORCHESTRATOR

# Progress Log

## 2026-06-11 18:40:00 Europe/Paris

Task created by ORCHESTRATOR. Scope validated by Cyril. Ready for CODEUR.

# Handoffs

# Tests

- [x] model unit test
- [x] seeder test
- [ ] full test suite regression

# Test Results

Pending.

# Review Notes

Pending.

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
