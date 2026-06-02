# Production → Local Sync (Non-Destructive)

Simple script to synchronize production data into the local PostgreSQL database **without touching the local schema**.

## Important Principles

- **NEVER** modifies local schema (no `migrate`, no `db:wipe`)
- **NEVER** touches production database (read-only dump only)
- **PRESERVES** local-only tables (`loops`, `loop_members`, `loop_messages`)
- **BACKFILLS** legacy data to the `main` organization
- **CREATES** detailed reports for validation

## Three Credential Sets

| Set | Purpose | Source |
|-----|---------|--------|
| **PROD credentials** | `pg_dump` read-only from production | `~/.config/bouclepro/prod-db.env` |
| **PostgreSQL admin** | create/drop/restore temp DB `bouclepro_prod_import_tmp` | `LOCAL_PG_ADMIN_PASSWORD` env var |
| **Laravel local** | read/write app DB `bouclepro` | `.env.pgsql` (DB_USERNAME, DB_PASSWORD) |

All three are required. Secrets are never printed or logged.

## Quick Start

From the repository root:

```bash
LOCAL_PG_ADMIN_PASSWORD='password' ./_bash_cyril/synchro_pgsql-avant-migration/sync-prod-to-local.sh
```

### Self-Test Mode (Before First Real Sync)

```bash
LOCAL_PG_ADMIN_PASSWORD='...' ./_bash_cyril/synchro_pgsql-avant-migration/sync-prod-to-local.sh --self-test
```

This validates:
- All tools available
- Directories exist
- Local credentials readable
- PostgreSQL admin connection works
- Temp DB can be created/dropped
- Bash syntax

No PROD dump, no ETL, no seeders.

## How Local Migrations Are Handled

The script automatically handles 3 scenarios when you have local migrations that don't exist in production:

### Scenario 1: Tenant Columns (`organization_id` / `community_id`)

If local migration adds tenant columns that don't exist in production:
- ✅ Script injects `organization_id`/`community_id` automatically with the `main` organization ID
- ✅ Backfill fills all NULL values
- **Example**: PRODUCTION `users` (no organization_id) → LOCAL `users` (with organization_id = main)

### Scenario 2: New Non-Tenant Columns

If local migration adds columns that don't exist in production:
- ⚠️ Production doesn't have this data, so rows will have NULL for these columns
- ✅ No errors, columns are simply ignored during import
- **Example**: PRODUCTION `users` (no phone) → LOCAL `users` (phone = NULL)
- **Fix**: Manually fill or use a seeder

### Scenario 3: New Tables

If local migration adds tables that don't exist in production:
- ✅ Tables are preserved (local-only, not touched)
- ✅ Reported as "Local tables preserved" in the sync report
- **Example**: LOCAL `organizations_settings` → preserved, not overwritten

### Best Practices

1. **Always run migrations first**: Execute `php artisan migrate` BEFORE running sync
2. **Check the sync report**: Look at "Ignored Columns" and "Local tables preserved" sections
3. **Seed new columns**: After sync, fill NULL columns with seeders if needed

## Recent Migrations Compatibility

**⚠️ IMPORTANT**: If you have run recent migrations locally (especially the `drop_community_id_from_tables.php` migration), you must read the compatibility analysis.

The script automatically handles these scenarios, but there are edge cases and risks to understand.

**Read the detailed analysis**: [MIGRATION_COMPATIBILITY_ANALYSIS.md](./MIGRATION_COMPATIBILITY_ANALYSIS.md)

This document covers:
- Detailed explanation of how the ETL script handles 3 migration scenarios
- Analysis of the critical `drop_community_id_from_tables.php` migration
- Potential risks and mitigations
- Testing strategy for Orchestrator
- **Status**: ✅ Compatible (not tested by AI, recommended for Orchestrator testing)

## What Happens

1. **Prerequisites check**
   - Verifies tools (`psql`, `pg_dump`, `pg_restore`, `php`)
   - Creates directories if needed
   - Checks production credentials file

2. **Load credentials**
   - Local DB config from `.env.pgsql`
   - Production credentials from `~/.config/bouclepro/prod-db.env`

3. **Verify local database**
   - Tests connectivity to local PostgreSQL
   - Verifies `main` organization exists

4. **Dump production**
   - Creates read-only dump of production database
   - Saves to `dumps/production_YYYYMMDD_HHMMSS.dump`

5. **Recreate temporary database**
   - Terminates existing connections
   - Drops `bouclepro_prod_import_tmp` if exists
   - Creates fresh temporary database
   - **Uses PostgreSQL admin credentials** (not Laravel user)

6. **Restore to temporary DB**
   - Restores production dump to temporary DB
   - **Never touches main local database**
   - **Uses PostgreSQL admin credentials** (not Laravel user)

7. **Run PHP ETL**
   - Connects to both temp and local DBs (as Laravel user)
   - Compares tables and columns
   - Imports common data with upsert strategy
   - Injects `main_id` for `organization_id`/`community_id` if missing from source
   - Respects FK ordering
   - Resets PostgreSQL sequences

8. **Run seeders**
   - `LegacyDataOrganizationSeeder` — backfills NULL organization_id/community_id
   - `QaAccountsSeeder` — injects QA test accounts
   - `php artisan optimize:clear` — clears cache

9. **Validation**
   - Checks `users.organization_id` for NULL values
   - Checks `users.community_id` if column exists
   - Verifies local tables exist
   - Reports database counters

10. **Cleanup**
    - Asks for confirmation before deleting temp DB
    - **If ETL fails, temp DB is preserved for diagnostics**
    - Deletes `bouclepro_prod_import_tmp`

## After Sync

### Validation Commands

Check users without organization:

```bash
php artisan tinker --execute="dump(DB::table('users')->whereNull('organization_id')->whereNotNull('email')->count());"
```

Expected: `0`

Check key tables:

```bash
php artisan tinker --execute="dump([
  'users' => DB::table('users')->count(),
  'services' => DB::table('services')->count(),
  'service_requests' => DB::table('service_requests')->count(),
  'transactions' => DB::table('transactions')->count(),
  'messages' => DB::table('messages')->count(),
  'blog_posts' => DB::table('blog_posts')->count(),
  'loops' => DB::table('loops')->count(),
]);"
```

Expected:
- All tables have numeric values (not 'missing')
- `loops`, `loop_members`, `loop_messages` have values from local development

Verify QA accounts exist:

```bash
php artisan tinker --execute="dump(DB::table('users')->where('email', 'like', 'test_%')->count());"
```

Expected: `> 0`

### Reports

The script generates two reports:

1. **Console output** — Real-time progress with ✓ and ⚠ markers
2. **Markdown file** — `_bash_cyril/synchro_pgsql-avant-migration/logs/rapport-final-YYYYMMDD-HHMMSS.md`

The final report contains:
- Tables imported (with row counts and strategy)
- Blacklisted tables
- Local tables preserved
- Tables ignored (with reasons)
- Columns ignored per table
- Backfills performed (organization_id/community_id)
- Sequences reset
- Counters before/after
- Validation recommendations

## File Structure

```
_bash_cyril/synchro_pgsql-avant-migration/
├── sync-prod-to-local.sh      # Main wrapper script
├── sync-prod-to-local.php     # PHP ETL logic
├── README.md                  # This file
├── dumps/                     # Production dumps (gitignored)
│   └── production_*.dump
├── logs/                      # Reports (gitignored)
│   └── rapport-final-*.md
│   └── etl-report-*.md
│   └── review-fix-*.md
└── tmp/                       # Temporary files (gitignored)
```

## Configuration

### Environment Variables

| Variable | Required | Default | Purpose |
|----------|----------|---------|---------|
| `LOCAL_PG_ADMIN_PASSWORD` | Yes | — | PostgreSQL admin password for temp DB |
| `LOCAL_PG_ADMIN_USER` | No | `postgres` | PostgreSQL admin username |

## Troubleshooting

### "Organization 'main' does not exist"

Create it first:

```bash
php artisan tinker --execute="
DB::table('organizations')->insert([
  'id' => Str::uuid(),
  'name' => 'Main',
  'slug' => 'main',
  'is_active' => true,
  'created_at' => now(),
  'updated_at' => now(),
]);
"
```

### "Cannot connect to local database"

Check PostgreSQL is running:

```bash
sudo service postgresql status
```

### "Missing required tool: psql"

Install PostgreSQL client tools:

```bash
sudo apt-get install postgresql-client
```

### ETL Failure

- Temp DB is **preserved** for diagnostics
- Check the ETL report in `logs/` for details
- Connect manually: `psql -h 127.0.0.1 -U postgres -d bouclepro_prod_import_tmp`

## Differences from `ai/scripts/pg-dump.sh`

| Feature | `pg-dump.sh prod-mirror` | `sync-prod-to-local.sh` |
|---------|------------------------|------------------------|
| Local schema | Destroyed and recreated | Preserved |
| Migrations | Runs `migrate --force` | Never runs migrate |
| Local tables | All wiped | Local tables preserved |
| Destructive | Yes (`db:wipe`) | No (ETL only) |
| Backfill | Via seeder | Via seeder + ETL |
| Target | Full mirror rebuild | Data sync only |
| Credentials | Uses Laravel user for everything | Separates admin vs app credentials |

## Architecture Notes

- **Organization = Tenant** — All data is tenant-scoped
- **Loop ≠ Tenant** — Loops are collaborative contexts, not security boundaries
- **Community/community_id = legacy** — Temporary compatibility layer
- **Main organization** — Default tenant for legacy data backfill

## License

Personal script for Cyril. Not part of the official repository workflow.