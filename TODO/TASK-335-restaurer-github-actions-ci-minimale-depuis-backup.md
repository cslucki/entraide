---
task_id: TASK-335
title: Restaurer GitHub Actions CI minimale depuis backup

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-335-restaurer-github-actions-ci-minimale-depuis-backup

priority: MEDIUM

created_at: 2026-06-22 21:45:01 Europe/Paris
updated_at: 2026-06-22 22:00:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: 2026-06-22 21:45:01 Europe/Paris
  unlocked_at: 2026-06-22 22:00:00 Europe/Paris

handoff: false

pr:
  status: READY
  url: null
---

# Objective

Restaurer une CI minimale GitHub Actions propre depuis un backup local, après suppression des workflows lors du TASK-326/TASK-327 public repo lockdown.

---

# Planned Actions

- [x] Vérifier état Git et synchroniser develop depuis main
- [x] Créer TASK-335 via script
- [x] Localiser les backups contenant `.github/workflows/`
- [x] Auditer les workflows trouvés (sécurité, secrets, PROD)
- [x] Restaurer `ci-postgresql.yml` + `phpunit.pgsql.xml` + `phpunit.xml`
- [x] Tests locaux (composer validate, caches, build)
- [x] Commit + finalize

---

# Progress Log

## 2026-06-22 21:45:01 Europe/Paris

Task created.

## 2026-06-22 21:50:00 Europe/Paris

### Backup discovery

Searched `/home/cyril` for `.github/workflows/` files.
Key finding: `/home/cyril/claude-code/sites/test.laravel-T157/.github/workflows/ci-postgresql.yml`
Also found: `phpunit.pgsql.xml` and `phpunit.xml` in same backup.

### Security audit

**Workflow `ci-postgresql.yml`:**
- `DB_PASSWORD: postgres` / `POSTGRES_PASSWORD: postgres` — CI service passwords, not real secrets
- No tokens, no PROD access, no SSH, no rsync, no Laravel Cloud, no R2/S3, no private paths
- Uses `actions/checkout@v4`, `shivammathur/setup-php@v2`, `actions/cache@v4` — standard actions
- Triggers on push/PR to main + develop — correct
- PostgreSQL 18 service with `bouclepro_test` — safe, isolated test DB
- Runs `php artisan migrate --force` + `db:seed --force` on test DB only
- Uses `phpunit.pgsql.xml` config — not main phpunit.xml
- ✅ SAFE to restore

**`phpunit.pgsql.xml`:**
- `DB_CONNECTION=pgsql`, DB credentials from CI env — safe
- Test APP_KEY (base64:...) — safe for CI
- ✅ SAFE

**`phpunit.xml`:**
- SQLite `:memory:` — safe for local testing
- ✅ SAFE

### Files restored

- `.github/workflows/ci-postgresql.yml`
- `phpunit.pgsql.xml`
- `phpunit.xml`

### Local validation

- `composer validate --no-check-publish`: ✅ OK
- `php artisan config:clear`: ✅ OK
- `php artisan view:clear`: ✅ OK
- `npm run build`: ✅ OK (pre-existing Vite chunk-size warning)

---

# Handoffs

N/A

---

# Tests

- [x] composer validate
- [x] config:clear, view:clear
- [x] npm run build
- [ ] php artisan test — blocked locally (no DB test service), will run in CI

---

# Test Results

- composer validate: OK
- Laravel caches: cleared OK
- npm build: OK (pre-existing Vite chunk warning)
- phpunit: not runnable locally (PostgreSQL service not available), CI will validate

---

# Review Notes

### Security

- No secrets restored
- No PROD credentials
- No private paths
- CI DB is `bouclepro_test` (isolated)
- APP_KEY in workflow is test-only

### Known limitations

- CI depends on GitHub-hosted PostgreSQL service (not available locally)
- `php artisan test` cannot run locally without PostgreSQL service
- Vite chunk >500k warning pre-existing

### Recommendation

GO for commit and merge. CI will validate on push.

---

# Closeout

- Finalized: 2026-06-22 22:00:00 Europe/Paris
- Branch: TASK-335-restaurer-github-actions-ci-minimale-depuis-backup
- Files: 3 restored from backup `test.laravel-T157`
- Status: DONE

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
