---
task_id: TASK-191
title: PostgreSQL local validation structured reproducible command

status: DONE

owner: ORCHESTRATOR

contributors: []

branch: TASK-191-postgresql-local-validation-structured-reproducible-command

priority: MEDIUM

created_at: 2026-06-01 14:30:24 Europe/Paris
updated_at: 2026-06-01 15:50:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: false

pr:
  status: OPEN
  url: https://github.com/cslucki/entraide/pull/36
---

# Objective

Create a structured, reproducible command to validate PostgreSQL runtime locally.

Deliverables:
1. `ai/scripts/pg-validate.sh` — single command that checks prerequisites, ensures PostgreSQL connectivity, creates `bouclepro_test` database for test isolation, runs migrations + seeders, executes full PHPUnit suite on PostgreSQL, reports results.
2. `phpunit.pgsql.xml` — set explicit `DB_DATABASE=bouclepro_test` so local test runs don't collide with dev database.
3. `ai/environment.md` — document the new pg-validate.sh workflow.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [ ] validate UI

---

# Progress Log

## 2026-06-01 15:50:00 Europe/Paris

Task completed.

Implementation:
- Created `ai/scripts/pg-validate.sh` — 6-step reproducible validation:
  1. Check prerequisites (psql, php, artisan, config files)
  2. Verify PostgreSQL connectivity
  3. Create `bouclepro_test` database if missing
  4. Ensure PostgreSQL mode is active
  5. Run `migrate:fresh --seed`
  6. Run `phpunit.pgsql.xml` test suite
- Updated `phpunit.pgsql.xml`: added explicit `<env name="DB_DATABASE" value="bouclepro_test"/>` for local test isolation from dev database.
- Updated `ai/environment.md` with `pg-validate.sh` command documentation.

Validation results:
- 837 passed, 11 skipped, 1756 assertions — all green on PostgreSQL.
- `bouclepro_test` database created and isolated.
- No production code modified.
- All tests fully pass on PostgreSQL runtime.

## 2026-06-01 14:30:24 Europe/Paris

Task created.

Owner:
ORCHESTRATOR

Branch:
TASK-191-postgresql-local-validation-structured-reproducible-command

Status:
IN_PROGRESS

# Handoffs

# Tests

- [x] feature tests (837 passed, 11 skipped — PostgreSQL runtime)
- [ ] browser validation (N/A — infrastructure script, no UI)
- [ ] responsive validation (N/A — infrastructure script, no UI)
- [ ] console inspection (N/A — infrastructure script, no UI)
- [ ] tenant validation (N/A — infrastructure script, no UI)

---

# Test Results

- PHPUnit (PostgreSQL via `phpunit.pgsql.xml`): 837 passed, 11 skipped, 1756 assertions
- `php vendor/bin/phpunit --configuration phpunit.pgsql.xml` exit code: 0
- `bouclepro_test` database created and used for test isolation
- No regressions vs SQLite runtime

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