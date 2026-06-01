# Development Environment

## Local Environment

Project runs inside WSL2 Ubuntu.

### WSL Host

- Host: WSL2
- OS: Ubuntu
- Local Linux IP: 127.0.0.1

### Windows Host Access

Windows accesses Laravel through:

- Windows IP: 172.27.130.89
- Local URL:
  https://test.laravel/dashboard

This URL must be used for:
- Playwright
- browser automation
- screenshots
- responsive validation
- UI testing

Do not use localhost from Windows browser tooling.

---

# Production Environment

Production product:
- BouclePro.com

Production infrastructure:
- Laravel Cloud

Production URL:
https://bouclepro.com

---

# Database

## Dual Runtime Strategy

This project supports two official runtime environments:

| Runtime    | File            | Engine      | Use Case                              |
|------------|-----------------|-------------|---------------------------------------|
| SQLite     | `.env.sqlite`   | SQLite      | Default lightweight dev & Playwright  |
| PostgreSQL | `.env.pgsql`    | PostgreSQL  | Production parity validation          |

### Environment file roles

- `.env` — active runtime (copied from one of the above, gitignored)
- `.env.sqlite` — full SQLite runtime config (APP_KEY, test creds, mail)
- `.env.pgsql` — full PostgreSQL runtime config (same APP_KEY, same creds)
- `.env.example` — onboarding template only (no real keys, no runtime assumptions)

### SQLite (default for local development)

- Fast, zero-config, no server required
- Ideal for quick dev and Playwright testing
- Activate via: `cp .env.sqlite .env`

### PostgreSQL (production parity)

- Same engine as Laravel Cloud (PostgreSQL 18)
- Required before merging migration changes
- Use to validate PostgreSQL-specific features
- Activate via: `cp .env.pgsql .env`

## Switching Between Databases

Use the helper script:

```bash
./ai/scripts/switch-db.sh sqlite   # switch to SQLite (.env.sqlite → .env)
./ai/scripts/switch-db.sh pgsql    # switch to PostgreSQL (.env.pgsql → .env)
./ai/scripts/switch-db.sh status   # show current DB connection & connectivity
```

The script automatically:
- validates the source `.env.*` file exists
- backs up the current `.env` to `.env.bak`
- clears the application cache after switching

Or manually:

```bash
cp .env.pgsql .env    # PostgreSQL (or .env.sqlite for SQLite)
php artisan optimize:clear
```

After switching, run migrations and seeders:

```bash
php artisan migrate:fresh --seed
```

## Production Dump & Sync Workflow

### Safety notes

- All dump/import operations require PostgreSQL to be running locally
- The script validates prerequisites (`pg_dump`, `pg_restore`, `psql`) before proceeding
- The script checks that `.env` is configured for PostgreSQL before dump/import
- Destructive imports (restore / reset) require interactive confirmation
- Dumps directory (`storage/app/dumps/`) is gitignored — see `.gitignore`
- PostgreSQL password is read from `.env.pgsql` (single source of truth)
- After import, the script automatically runs `php artisan migrate --force` and clears the cache

### Export local PostgreSQL

```bash
./ai/scripts/pg-dump.sh dump                   # full dump
./ai/scripts/pg-dump.sh schema-only            # schema only
./ai/scripts/pg-dump.sh data-only              # data only
```

### Import a dump

```bash
./ai/scripts/pg-dump.sh import storage/app/dumps/bouclepro_2026-05-12_*.sql
```

### Full reset from latest dump (import + migrate + cache clear)

```bash
./ai/scripts/pg-dump.sh reset
```

This uses the most recent dump in `storage/app/dumps/` and runs the full workflow:
import → `migrate --force` → `optimize:clear`.

### Production dump (Laravel Cloud)

```bash
# Get production connection details
php artisan cloud:db:show

# Dump production database
pg_dump --host=<prod-host> --port=5432 \
  --username=<prod-user> --dbname=<prod-db> \
  --format=custom --no-owner \
  --file=storage/app/dumps/production_$(date +%Y-%m-%d_%H-%M-%S).sql

# Import into local PostgreSQL
./ai/scripts/pg-dump.sh import storage/app/dumps/production_<file>.sql

# Or full reset from latest dump
./ai/scripts/pg-dump.sh reset
```

### Production Mirror Workflow (prod → local)

Full 4-phase pipeline to mirror production data into local PostgreSQL.

```bash
./ai/scripts/pg-dump.sh prod-mirror
```

Phases:
1. **Dump** — prompts for production credentials (from Laravel Cloud dashboard or `php artisan cloud:db:show`), dumps to `storage/app/dumps/production_<timestamp>.sql`
2. **Import** — `pg_restore --clean` into local PostgreSQL (destructive — interactive confirmation required)
3. **Migrate** — runs `php artisan migrate --force` for additive local migrations
4. **Cache clear** — `php artisan optimize:clear`

Alternatively, dump production separately:

```bash
./ai/scripts/pg-dump.sh prod-dump
```
Then import via:

```bash
./ai/scripts/pg-dump.sh import storage/app/dumps/production_<file>.sql
```

Credentials are requested interactively — never stored in scripts.

### Post-mirror validation (CODE)

After mirror completes, validate runtime parity:

```bash
./ai/scripts/switch-db.sh pgsql   # ensure PostgreSQL is active
php artisan test                    # PHPUnit test suite
npx playwright test                 # Playwright browser tests
```

### List available dumps

```bash
./ai/scripts/pg-dump.sh list
```

## PostgreSQL Local Setup

The local PostgreSQL instance runs PostgreSQL 18 with:
- Database: `bouclepro` (dev)
- Database: `bouclepro_test` (test isolation)
- User: `bouclepro`
- Password: stored in `.env.pgsql`

## PostgreSQL Local Validation (Reproducible Command)

A single, structured validation command ensures PostgreSQL is fully operational:

```bash
./ai/scripts/pg-validate.sh
```

The script runs these steps:
1. **Prerequisites** — checks `psql`, `php`, `artisan`, config files
2. **Connectivity** — verifies PostgreSQL is reachable on `127.0.0.1:5432`
3. **Test database** — creates `bouclepro_test` database if missing (test isolation)
4. **Switch mode** — ensures `.env` is configured for PostgreSQL
5. **Migrate & seed** — runs `php artisan migrate:fresh --seed`
6. **Test suite** — runs full PHPUnit via `phpunit.pgsql.xml`
7. **Results** — reports pass/fail summary, returns appropriate exit code

Test isolation: Local PHPUnit runs use `bouclepro_test` database (via `phpunit.pgsql.xml`), keeping test data separate from the `bouclepro` dev database. CI uses the same configuration with `bouclepro_test`.

## PostgreSQL Compatibility Rules

All migrations must be tested on BOTH SQLite and PostgreSQL before merge.

Agents must always verify SQL compatibility.

Avoid:
- SQLite-only queries (e.g., `json` without `jsonb`)
- PostgreSQL-incompatible syntax
- Native enum type accumulation (use `->default()` correctly)
- Skipping PostgreSQL validation before migration PRs

## PostgreSQL CI Validation (GitHub Actions)

A dedicated CI workflow validates PostgreSQL compatibility on every push/PR to `main` or `develop`:

- **Workflow file:** `.github/workflows/ci-postgresql.yml`
- **PHPUnit config:** `phpunit.pgsql.xml`
- **Service:** PostgreSQL 17 container with `bouclepro_test` database
- **PHP:** 8.4 with `pdo_pgsql` extension
- **Steps:** migrate → seed → test (all 294 tests)

### Run PostgreSQL tests locally

```bash
php artisan test --configuration phpunit.pgsql.xml
```

Prerequisites: a PostgreSQL server running on `127.0.0.1:5432` with a `bouclepro_test` database and `postgres`/`postgres` credentials.

---

# Stack Versions

- PHP 8.4
- Laravel 13.7
- Livewire 3
- Alpine.js
- Tailwind CSS v4
- Node 22
- Vite

---

# Installed Terminal Tools

Preferred tools installed in WSL:

- batcat
- rg
- fzf
- lazygit
- git
- tmux

Agents should prefer these tools over basic alternatives.

Examples:
- use `batcat` instead of `cat`
- use `rg` instead of `grep`

---

# Browser & Playwright Rules

Agents are encouraged to use browser tooling for:

- screenshots
- UI debugging
- responsive testing
- console inspection
- Livewire inspection
- Alpine.js validation

Preferred workflow:
1. inspect UI
2. inspect console
3. inspect network
4. capture screenshots
5. only then modify code

## Playwright QA System

**Documentation:** `ai/playwright/README.md`

**Run tests:**
```bash
npx playwright test                    # All tests
npx playwright test --ui             # UI mode
npx playwright test --headed           # Watch browser
npx playwright show-report ai/playwright/reports/html
```

**Browser projects configured:**
- Chromium (Desktop Chrome)
- Firefox
- WebKit (Safari)
- Mobile Chrome

**WebKit is intentionally enabled in Playwright projects to improve cross-browser frontend quality and Safari compatibility.**

**Strict Account Separation:**
- `loginAsAdmin()` → TEST_ADMIN_* (for /admin/* routes ONLY)
- `loginAsMember()` → TEST_MEMBER1_* (for global platform tests, OUTSIDE CPME)
- CPME accounts reserved for future tenant-isolation testing

**Test users:**
See `.env` and `ai/playwright/README.md` for complete account details.

**Environment variables (required in .env):**
- TEST_ADMIN_* - Admin user for admin routes
- TEST_MEMBER1_* - Global member (Alice, OUTSIDE CPME)
- TEST_MEMBER2_* - Global member (Cyril, OUTSIDE CPME)
- TEST_MEMBER_OF_CPME1_* - CPME member (reserved for future)
- TEST_MEMBER_OF_CPME2_* - CPME member (reserved for future)

**Device profiles available:**
- Desktop: 1280x720, 1920x1080
- Tablet: 768x1024, 1024x768
- Mobile: 375x667, 414x896


---

# Standard Commands

## Development

```bash
php artisan serve
npm run dev
```

## Tests

```bash
php artisan test
```

## Build

```bash
npm run build
```

---

# Git Workflow

- Main branch: main
- One branch per task
- Never commit directly to main
- Keep commits atomic and focused

Branch naming example:

TASK-051-navbar-livewire-fix

---


# HTTPS Local Development

Local development uses HTTPS with a local development certificate.

Playwright/browser automation must use:

```javascript
ignoreHTTPSErrors: true

---

# Important Rules

Before modifying frontend behavior:
- inspect visually
- inspect DOM
- inspect console
- inspect Livewire requests
- inspect Alpine state

Never assume UI behavior without browser validation.