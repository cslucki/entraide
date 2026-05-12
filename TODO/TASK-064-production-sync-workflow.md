---
task_id: TASK-064
title: production-sync-workflow

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-064-production-sync-workflow

priority: MEDIUM

created_at: 2026-05-12 17:26:08 Europe/Paris
updated_at: 2026-05-12 17:26:08 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: 2026-05-12 17:26:08 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Audit and improve the production sync workflow:

1. Add safety checks to PostgreSQL dump/import scripts
2. Improve environment switching script
3. Align documentation with actual workflow
4. Add missing safety guards (prerequisite checks, connection validation, confirmations)
5. Refactor hardcoded credentials to read from .env.pgsql (single source of truth)

---

# Planned Actions

- [x] audit pg-dump.sh
- [x] audit switch-db.sh
- [x] audit environment.md
- [x] implement improvements
- [x] run tests
- [ ] ~validate UI (not applicable — shell scripts only)~

---

# Progress Log

## 2026-05-12 17:26:08 Europe/Paris

Task created.

Owner: OPENCODE
Branch: TASK-064-production-sync-workflow
Status: IN_PROGRESS

## 2026-05-12 17:40:00 Europe/Paris

### Audit findings

**pg-dump.sh:**
- Hardcoded PostgreSQL password on line 24 — security risk, single source of truth violation
- No prerequisite check (pg_dump, pg_restore, psql may not be installed)
- No PostgreSQL connectivity check before dump/import — fails cryptically if PG is down
- No runtime env check — can dump SQLite-when user thinks they're on PostgreSQL
- No confirmation prompt before destructive import — --clean pg_restore wipes DB silently
- No auto-migrate after import — user must manually remember `php artisan migrate`
- No cache clearing after import — stale config cache
- `prod-dump` was just echo instructions with no .gitignore verification
- No `reset` command for full import → migrate → cache clear workflow

**switch-db.sh:**
- No source file existence check — cp silently creates empty .env if source missing
- No .env backup — overwrites current .env irreversibly
- No cache clearing after switch — stale config references old DB
- `status` only shows raw .env values, no connectivity check
- No uncommitted changes warning

**environment.md:**
- Missing cache clearing instruction after switch
- Missing safety notes for destructive import
- Missing documentation for full workflow (import → migrate → cache clear)

### Implemented improvements

**pg-dump.sh:**
- `check_prereqs()` — validates pg_dump, pg_restore, psql are installed
- `check_pg_connection()` — tests PostgreSQL reachability before any operation
- `check_runtime_env()` — warns if current .env is not configured for PostgreSQL
- `confirm_destructive()` — interactive confirmation before import/reset
- Password now read from `.env.pgsql` via grep (single source of truth)
- Auto-run `php artisan migrate --force` after import
- Auto-clear `php artisan optimize:clear` after import
- New `reset` command: import latest dump + migrate + cache clear
- `list` now shows gitignore notice
- `prod-dump` now references gitignore instead of raw instruction

**switch-db.sh:**
- Source file existence validation (`.env.sqlite` / `.env.pgsql` must exist)
- `check_uncommitted()` — warns before switching with dirty working tree
- Auto-backup current `.env` to `.env.bak` before overwriting
- Auto-clear `php artisan optimize:clear` after switching
- `status` now checks PostgreSQL connectivity when DB_CONNECTION=pgsql
- Shows ✅/❌ for PostgreSQL reachability

**environment.md:**
- Added "Safety notes" section for Production Dump workflow
- Documented auto-cache-clear behavior
- Documented new `reset` command
- Updated manual switch instructions to include cache clear
- Replaced `cp .env.sqlite .env` primary example with `cp .env.pgsql .env` (PostgreSQL-primary)
- Added note about .gitignore for dumps directory
- Added note about password source (`.env.pgsql`)

---

# Handoffs

N/A — single agent task completed.

---

# Tests

- [x] PHPUnit 294/294 passed on PostgreSQL
- [x] Pint passed (no PHP files modified)

---

# Test Results

```
Tests:    294 passed (597 assertions)
Duration: 6.05s
```

Pint: passed (no PHP files touched, only shell scripts + markdown)

---

# Review Notes

- No PHP files modified — zero risk to application logic
- All changes are to shell scripts and markdown documentation
- SQLite compatibility preserved (no SQL/PHP changes)
- PostgreSQL compatibility preserved (same connection params, just read from .env.pgsql)
- CI compatibility preserved (scripts are local dev tooling, not CI)
- Minimal diff — focused on safety guards, no architectural changes
- Password no longer hardcoded — reads from `.env.pgsql`

---

# Review Notes

Pending.