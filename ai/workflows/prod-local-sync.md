# Production → Local Database Sync Workflow

## Executive Summary

This workflow synchronizes production database data into the local PostgreSQL development environment.

**Three distinct commands exist:**

| Command | When to Use | What it Does |
|---------|-------------|--------------|
| `prod-mirror` | New sync from PROD (no recent dump exists) | Dump PROD → wipe local → restore → migrate → backfill legacy → cache clear |
| `mirror-import <file>` | Reuse existing PROD dump | Wipe local → restore from file → migrate → backfill legacy → QA accounts → cache clear |
| `import <file>` | Legacy/edge cases | Restore with `--clean` (may fail on FK ordering) |

**Recommended path:** Always prefer `mirror-import` if a valid dump exists. Use `prod-mirror` only when you need a fresh PROD dump.

---

## Prerequisites

1. **Run from dedicated task branch** (TASK-*)
2. **Run preflight check:**
   ```bash
   ./ai/scripts/safe-sync-preflight.sh --dry-run
   ```
3. **Switch to PostgreSQL:**
   ```bash
   ./ai/scripts/switch-db.sh pgsql
   ```
4. **Clean backup if created:**
   ```bash
   rm -f .env.bak
   ```

If preflight reports FAIL > 0, stop and fix issues before proceeding.

---

## Production Credentials

**Private file:** `/home/cyril/.config/bouclepro/prod-db.env`

**Required permissions:** `600`

**Format:**
```bash
DB_HOST=...
DB_PORT=5432
DB_USERNAME=...
DB_PASSWORD=...
DB_NAME=...
DB_CONNECTION=pgsql
```

**Rules:**
- Never print, commit, or log credentials.
- Never copy credentials into tracked files.
- Never display `DB_PASSWORD` in output.
- Use `prod-mirror` only after confirming credentials file exists.

---

## Commands Reference

### `prod-mirror` — Full PROD → Local Sync

```bash
./ai/scripts/pg-dump.sh prod-mirror
```

**Workflow:**
1. Load PROD credentials from `/home/cyril/.config/bouclepro/prod-db.env` (or prompt interactively)
2. Dump production database to `storage/app/dumps/production_<timestamp>.sql` (custom format, no-owner)
3. Wipe local tables: `php artisan db:wipe --force`
4. Restore PROD data: `pg_restore --host=127.0.0.1 --no-owner`
5. Run local migrations: `php artisan migrate --force`
6. Backfill legacy NULL data: `php artisan db:seed --class=LegacyDataOrganizationSeeder --force`
7. Clear cache: `php artisan optimize:clear`

**When to use:**
- No recent PROD dump exists
- You need fresh data from production
- Production schema has changed significantly

---

### `mirror-import <file>` — Reuse Existing Dump

```bash
./ai/scripts/pg-dump.sh mirror-import storage/app/dumps/production_2026-05-24_13-08-02.sql
```

**Workflow:**
1. Verify dump file exists
2. Wipe local tables: `php artisan db:wipe --force`
3. Restore from file: `pg_restore --host=127.0.0.1 --no-owner`
4. Run local migrations: `php artisan migrate --force`
5. Backfill legacy NULL data: `php artisan db:seed --class=LegacyDataOrganizationSeeder --force`
6. Inject QA accounts: `php artisan db:seed --class=QaAccountsSeeder --force`
7. Clear cache: `php artisan optimize:clear`

**When to use:**
- A valid PROD dump already exists in `storage/app/dumps/`
- You want to avoid re-dumping PROD
- You need to restore from a specific timestamp

**Why `mirror-import` vs `import`:**
- `import` uses `--clean --if-exists`, which can fail on FK ordering
- `mirror-import` uses `db:wipe` first, which cleanly drops all tables including local ones (loops, etc.)
- `mirror-import` includes automatic QA accounts injection
- `mirror-import` includes legacy data backfill

---

### `import <file>` — Legacy Command (Not Recommended)

```bash
./ai/scripts/pg-dump.sh import <dump-file>
```

**Why NOT recommended for PROD sync:**
- Uses `pg_restore --clean --if-exists`, which may silently fail on foreign key ordering
- Does not wipe local tables first, so local-only tables (loops) can cause conflicts
- Does not automatically run seeders (LegacyDataOrganizationSeeder, QaAccountsSeeder)
- Better for restoring local snapshots, not production mirrors

---

## Why `db:wipe` First? (Critical Insight from TASK-121)

The robust path for PROD → local sync is:

```bash
php artisan db:wipe --force
pg_restore --no-owner <dump>
php artisan migrate --force
```

**Why NOT `pg_restore --clean` or `--data-only`:**
- `pg_restore --data-only` does NOT sort tables by FK dependency order
- This causes silent failures: tables with FKs (services, transactions, messages) fail to import because referenced tables (users) aren't loaded yet
- `pg_restore --clean` cannot handle local-only tables (loops) that depend on PROD tables

**The `db:wipe` solution:**
1. `db:wipe` drops ALL tables cleanly (including local ones like loops)
2. Full `pg_restore` respects FK ordering
3. `migrate --force` adds local-only tables (loops, loop_members, loop_messages)

---

## Why `LegacyDataOrganizationSeeder`? (Critical Insight from TASK-122)

After importing PROD data, you MUST run:

```bash
php artisan db:seed --class=LegacyDataOrganizationSeeder --force
```

**Why:**
- Production data (pre-tenant architecture) has NULL `community_id` and `organization_id`
- The default org resolver returns the default organization (boucletest)
- But controllers filter by `community_id = boucletest.id`, finding zero results
- Result: `/membres`, `/blog`, `/explorer` show empty pages

**What the seeder does:**
- Backfills NULL `community_id` and `organization_id` on all 7 tables:
  - users (19 legacy users)
  - services (7)
  - service_requests (4)
  - blog_posts (1)
  - transactions (2)
  - loop_members (if any)
  - loop_messages (if any)
- Sets `Setting::default_organization_id` for consistent resolution
- Idempotent — safe to run multiple times

---

## Why `QaAccountsSeeder`?

After PROD sync, you MUST inject QA accounts:

```bash
php artisan db:seed --class=QaAccountsSeeder --force
```

**Why:**
- QA accounts are NOT in production (they are dev-only)
- Tests require specific QA users (TEST_ADMIN, TEST_MEMBER1, TEST_MEMBER2)
- These accounts allow testing admin/member dashboard separation
- They allow validating tenant isolation without touching production data

**What it does:**
- Creates 5 QA accounts with known credentials (defined in `.env.pgsql` TEST_* variables)
- Assigns them to test organizations (CPME, BNI, etc.)
- Enables Playwright and PHPUnit tests to run with isolated QA users

**Environment variables required (values in `.env.pgsql`, never print them):**
- `TEST_ADMIN_LOGIN`
- `TEST_ADMIN_PASSWORD`
- `TEST_MEMBER1_LOGIN`
- `TEST_MEMBER1_PASSWORD`
- `TEST_MEMBER2_LOGIN`
- `TEST_MEMBER2_PASSWORD`

---

## Why Local Migrations After Restore?

After `pg_restore`, you MUST run:

```bash
php artisan migrate --force
```

**Why:**
- PROD dump does NOT include local-only migration tables (loops, loop_members, loop_messages)
- These tables exist in develop but not in production
- They depend on PROD tables (users, communities, services, etc.)
- Running migrations after restore adds these local tables cleanly

**Order matters:**
1. `db:wipe` — drop everything including local tables
2. `pg_restore` — restore PROD schema + data with correct FK ordering
3. `migrate --force` — add local-only tables (loops, etc.)

---

## Where Are Dumps Stored?

**Directory:** `storage/app/dumps/`

**Gitignored:** Yes (see `.gitignore`)

**Naming convention:**
- Production dumps: `production_<timestamp>.sql` (from `prod-mirror` or `prod-dump`)
- Local dumps: `bouclepro_<timestamp>.sql` (from `dump` command)

**Format:** PostgreSQL custom format (not plain text)

**Size:** Typically 100KB-500KB for production (depends on data volume)

---

## Validations After Sync

### 1. Database Counters

```bash
php artisan tinker --execute="
dump([
    'users' => DB::table('users')->count(),
    'communities' => DB::table('communities')->count(),
    'services' => DB::table('services')->count(),
    'service_requests' => DB::table('service_requests')->count(),
    'blog_posts' => DB::table('blog_posts')->count(),
    'transactions' => DB::table('transactions')->count(),
    'loops' => Schema::hasTable('loops') ? DB::table('loops')->count() : 'missing',
    'loop_members' => Schema::hasTable('loop_members') ? DB::table('loop_members')->count() : 'missing',
    'loop_messages' => Schema::hasTable('loop_messages') ? DB::table('loop_messages')->count() : 'missing',
]);
"
```

**Expected after successful sync:**
- `users` > 0 (19 PROD + 5 QA = 24 minimum)
- `communities` >= 1 (at least default org)
- `services` >= 0 (depends on PROD)
- `service_requests` >= 0 (depends on PROD)
- `blog_posts` >= 0 (depends on PROD)
- `transactions` >= 0 (depends on PROD)
- `loops`, `loop_members`, `loop_messages`: numeric values (not 'missing')

### 2. QA Accounts Verification

```bash
php artisan tinker --execute="
dump([
    'qa_admin_exists' => DB::table('users')->where('email', env('TEST_ADMIN_LOGIN'))->exists(),
    'qa_member1_exists' => DB::table('users')->where('email', env('TEST_MEMBER1_LOGIN'))->exists(),
    'qa_member2_exists' => DB::table('users')->where('email', env('TEST_MEMBER2_LOGIN'))->exists(),
]);
"
```

**Expected:** All three should return `true`

### 3. Feature Tests

```bash
php artisan test tests/Feature/T0752DefaultOrganizationResolutionTest.php
php artisan test tests/Feature/T0754DashboardMembersExchangesTenantSafetyTest.php
```

**Expected:** All tests pass

### 4. Page Validation (if server running)

- `/membres` — Should display members (not empty)
- `/blog` — Should display blog posts if any
- `/explorer` — Should display services/demands if any
- `/dashboard` — Should work with QA account login

---

## Failures and Rollback

### If PreFlight Fails

```bash
# Check preflight output for FAIL items
./ai/scripts/safe-sync-preflight.sh --dry-run
```

**Common issues:**
- PostgreSQL client tools missing (`pg_dump`, `pg_restore`, `psql`)
- Local PostgreSQL not running
- `.env.pgsql` missing or invalid
- Git working tree has uncommitted changes (review before sync)

### If Import Fails

**Stop immediately.** Do NOT run migrations.

1. Restore from pre-sync local dump (if you created one)
2. Check error messages for FK constraint issues
3. Verify dump file is valid and complete
4. Check PostgreSQL connectivity
5. Review `pg-dump.sh` output for specific error

**If using `mirror-import`:**
- The error is likely local PostgreSQL configuration or permissions
- Check that user `bouclepro` has full access to database
- Verify TCP connection works: `psql -h 127.0.0.1 -U bouclepro -d bouclepro`

### If LegacyDataOrganizationSeeder Fails

**Common causes:**
- `organizations` table missing (should not happen after migrate)
- Default community missing (manually create or check seeders)
- Permission issue on database

**Resolution:**
```bash
php artisan migrate:status
php artisan db:seed --class=DatabaseSeeder --force
```

---

## Never Do These

- **Never** print `DB_PASSWORD`, `PGPASSWORD`, or any secrets
- **Never** commit dump files to git
- **Never** write secrets in TASK files, logs, or screenshots
- **Never** run `prod-mirror` without preflight
- **Never** merge task branch before validation
- **Never** touch main or PROD database
- **Never** use `pg_restore` without `-h 127.0.0.1` (uses socket vs TCP)
- **Never** run migrations before restore when syncing from PROD
- **Never** skip `LegacyDataOrganizationSeeder` after PROD import
- **Never** skip `QaAccountsSeeder` after PROD import

---

## Quick Reference

### Full Sync with New Dump
```bash
./ai/scripts/safe-sync-preflight.sh --dry-run
./ai/scripts/switch-db.sh pgsql
rm -f .env.bak
./ai/scripts/pg-dump.sh prod-mirror
php artisan test
```

### Sync from Existing Dump (Recommended)
```bash
./ai/scripts/safe-sync-preflight.sh --dry-run
./ai/scripts/switch-db.sh pgsql
rm -f .env.bak
./ai/scripts/pg-dump.sh mirror-import storage/app/dumps/production_2026-05-24_13-08-02.sql
php artisan test
```

### Validate After Sync
```bash
php artisan tinker --execute="dump(['users' => DB::table('users')->count()]);"
php artisan test tests/Feature/T0752DefaultOrganizationResolutionTest.php
php artisan test tests/Feature/T0754DashboardMembersExchangesTenantSafetyTest.php
```

---

## Architecture Notes

### Tenant Safety

- Organization = Tenant
- Loop ≠ Tenant
- `community_id` / `current_community` = legacy temporary
- This sync workflow preserves tenant isolation
- Legacy data is backfilled to default organization (not cross-org)

### Runtime Parity

- This workflow maintains runtime parity between SQLite and PostgreSQL
- Tests should pass on both runtimes after sync
- Schema differences are handled by migrations after restore

### Legacy Compatibility

- The seeder-based backfill avoids breaking changes
- No database migrations are added for legacy data
- No global scopes are bypassed
- Production behavior is not affected