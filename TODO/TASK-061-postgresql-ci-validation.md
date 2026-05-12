---
task_id: TASK-061
title: PostgreSQL CI validation

status: IN_REVIEW

owner: OpenCode

contributors: []

branch: TASK-061-postgresql-ci-validation

priority: MEDIUM

created_at: 2026-05-12 15:08:20 Europe/Paris
updated_at: 2026-05-12 15:50:00 Europe/Paris

labels: []

lock:
  status: LOCKED
  agent: OpenCode
  since: 2026-05-12 15:08:20 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Create a GitHub Actions CI workflow that validates PostgreSQL compatibility on every push/PR to main/develop.

The workflow must:
- run PHP 8.4 with pgsql extension
- start a PostgreSQL 17 service container
- create a `bouclepro_test` database
- run migrations and seeders
- execute all 294 PHPUnit tests using a dedicated `phpunit.pgsql.xml` config

Deliverables:
- `.github/workflows/ci-postgresql.yml` — CI workflow
- `phpunit.pgsql.xml` — PostgreSQL PHPUnit configuration
- `ai/environment.md` update — CI documentation

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [ ] validate UI (not needed — no UI changes)

---

# Progress Log

## 2026-05-12 15:08:20 Europe/Paris

Task created.

Owner:
OpenCode

Branch:
TASK-061-postgresql-ci-validation

Status:
IN_PROGRESS

## 2026-05-12 15:20:00 Europe/Paris

Created deliverables:

1. `phpunit.pgsql.xml` — PostgreSQL PHPUnit config
   - overrides DB_CONNECTION=pgsql
   - inherits DB_HOST/PORT/DATABASE/USERNAME/PASSWORD from .env (local) or CI workflow env
   - keeps standard test settings (CACHE_STORE=array, QUEUE_CONNECTION=sync, etc.)

2. `.github/workflows/ci-postgresql.yml` — GitHub Actions workflow
   - PHP 8.4 with pdo_pgsql extension
   - PostgreSQL 17 service container
   - composer caching
   - migrate → seed → test pipeline

3. Updated `ai/environment.md` with CI documentation section

## 2026-05-12 15:35:00 Europe/Paris

Initial test run revealed 10 errors + 4 failures on PostgreSQL.
Root causes identified:
- 8 errors: `personal_access_tokens.tokenable_id` uses `morphs()` (bigint) but User model uses UUIDs
  → Fixed: changed to `uuidMorphs()` in migration
- 4 failures: 2 duplicate from error cascade, 2 real LIKE case-sensitivity issues
- Remaining 2 failures: pre-existing HIGH risk (LIKE → ILIKE from T060 audit)

## 2026-05-12 15:50:00 Europe/Paris

Final test results validated:

| Engine    | Tests | Assertions | Pass | Fail | Notes |
|-----------|-------|------------|------|------|-------|
| SQLite    | 294   | 597        | 294  | 0    | no regression |
| PostgreSQL| 294   | 597        | 292  | 2    | 2 LIKE failures (T063) |

The 2 PostgreSQL failures are the known LIKE case-sensitivity issue
(T060 audit — HIGH risk #1). These are out of scope for T061 and belong to T063.

Migration fix applied:
- `database/migrations/2026_05_01_112744_create_personal_access_tokens_table.php`
  Changed `morphs('tokenable')` → `uuidMorphs('tokenable')`
  Needed because User model uses UUIDs (HasUuids trait).
  SQLite works with either; PostgreSQL requires uuidMorphs for UUID models.
  No regression on SQLite (294/294 pass).

---

# Handoffs

## 2026-05-12 15:50:00 Europe/Paris — Ready for Review

### Current State
- TASK-061: **IN_REVIEW**
- 3 files created, 1 migration fix, 1 doc update
- 294/294 SQLite ✅ | 292/294 PostgreSQL ✅ (2 pre-existing LIKE failures)

### Deliverables
1. `.github/workflows/ci-postgresql.yml` — CI workflow
2. `phpunit.pgsql.xml` — PostgreSQL PHPUnit config
3. `ai/environment.md` — CI documentation section
4. `database/migrations/2026_05_01_112744_create_personal_access_tokens_table.php` — uuidMorphs fix

### Next Steps
1. Merge TASK-061
2. T062: transaction locking safety (lockForUpdate on points)
3. T063: search portability (LIKE → ILIKE on PostgreSQL)
4. T064: production sync workflow

### Known Issues
- 2 LIKE sensitivity failures on PostgreSQL (tracked for T063)
- `phpunit.pgsql.xml` requires PostgreSQL accessible at 127.0.0.1:5432 with `.env` providing credentials

---

# Tests

- [x] feature tests (294 SQLite ✅, 292 PostgreSQL ✅)
- [ ] browser validation (not needed — no UI changes)
- [ ] responsive validation (not needed — no UI changes)
- [ ] console inspection (not needed — no UI changes)
- [ ] tenant validation (no scope changes)

---

# Test Results

2026-05-12 15:50:00 Europe/Paris

| Engine    | Tests | Assertions | Pass | Fail |
|-----------|-------|------------|------|------|
| SQLite    | 294   | 597        | 294  | 0    |
| PostgreSQL| 294   | 597        | 292  | 2    |

2 PostgreSQL failures are LIKE case-sensitivity (pre-existing, tracked for T063).

---

# Review Notes

## Migration Fix: morphs → uuidMorphs

- **File:** `database/migrations/2026_05_01_112744_create_personal_access_tokens_table.php`
- **Change:** `$table->morphs('tokenable')` → `$table->uuidMorphs('tokenable')`
- **Why:** User model uses `HasUuids` trait. SQLite doesn't enforce column types, so `morphs` (bigint) silently accepted UUID strings. PostgreSQL strictly validates types and rejects UUIDs for bigint columns.
- **Safety:** Verified 294/294 on SQLite — no regression.

## CI workflow design

- Uses `php vendor/bin/phpunit --configuration phpunit.pgsql.xml` directly (not `php artisan test` — artisan doesn't support `--configuration` flag)
- Job-level `env` block provides DB connection vars; phpunit.pgsql.xml provides test-appropriate defaults (CACHE_STORE=array, etc.)
- PostgreSQL service container uses `postgres:17` image with health check
- Database `bouclepro_test` created automatically by the service container via `POSTGRES_DB`
