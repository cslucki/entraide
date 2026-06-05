#!/bin/bash
# =========================================================
# SYNC PRODUCTION TO LOCAL - Non-destructive
# =========================================================
# Synchronise PRODUCTION data into LOCAL PostgreSQL without
# touching the local schema.
#
# NEVER:
#   - Modifies local schema
#   - Runs php artisan migrate
#   - Runs php artisan db:wipe
#   - Touches PROD database (read-only)
#
# ALWAYS:
#   - Preserves local-only tables (loops, loop_members, loop_messages)
#   - Backfills legacy data to 'main' organization
#   - Creates detailed reports
#
# Three credential sets:
#   1. PROD credentials  → pg_dump read-only
#   2. PostgreSQL admin  → create/drop/restore temp DB
#   3. .env.pgsql         → app DB read/write
#
# Usage:
#   LOCAL_PG_ADMIN_PASSWORD='...' ./sync-prod-to-local.sh
#   LOCAL_PG_ADMIN_PASSWORD='...' ./sync-prod-to-local.sh --self-test
#
# =========================================================

set -euo pipefail

# Configuration
BASE_DIR="$(cd "$(dirname "$(readlink -f "$0")")/.." && pwd)"
DUMPS_DIR="$BASE_DIR/synchro_pgsql-avant-migration/dumps"
LOGS_DIR="$BASE_DIR/synchro_pgsql-avant-migration/logs"
TMP_DIR="$BASE_DIR/synchro_pgsql-avant-migration/tmp"
ENV_FILE="$BASE_DIR/.env"
ENV_PGSQL="$BASE_DIR/.env.pgsql"
PROD_CREDENTIALS_FILE="/home/cyril/.config/bouclepro/prod-db.env"
PHP_SCRIPT="$BASE_DIR/synchro_pgsql-avant-migration/sync-prod-to-local.php"

# Local DB config (read from Laravel env)
LOCAL_HOST="127.0.0.1"
LOCAL_PORT="5432"

# PostgreSQL admin user for temp DB management
LOCAL_PG_ADMIN_USER="${LOCAL_PG_ADMIN_USER:-postgres}"
LOCAL_PG_ADMIN_PASSWORD="${LOCAL_PG_ADMIN_PASSWORD:-}"

# Temp DB name
TEMP_DB="bouclepro_prod_import_tmp"
TEMP_DB_TEST="bouclepro_sync_self_test_tmp"

# Timestamps
TIMESTAMP=$(date '+%Y%m%d_%H%M%S')
LOG_FILE="$LOGS_DIR/rapport-final-$TIMESTAMP.md"

# Track ETL success for conditional cleanup
ETL_SUCCESS=false

# =========================================================
# Helpers
# =========================================================
log() {
    echo "[$(date '+%H:%M:%S')] $1" >&2
}

error() {
    echo "ERROR: $1" >&2
    exit 1
}

confirm() {
    local msg="$1"
    echo ""
    echo "⚠️  $msg"
    read -rp "Are you sure? (y/N): " confirm
    if [ "$confirm" != "y" ]; then
        echo "Aborted."
        exit 0
    fi
}

# =========================================================
# Self-test mode
# =========================================================
self_test() {
    echo ""
    echo "═════════════════════════════════════════════════"
    echo "  SELF-TEST MODE"
    echo "  Validates environment without touching PROD"
    echo "═════════════════════════════════════════════════"
    echo ""

    # 1. Check tools
    log "Checking prerequisites..."
    for cmd in psql pg_dump pg_restore php bash; do
        if ! command -v "$cmd" &>/dev/null; then
            error "Missing required tool: $cmd"
        fi
    done

    # 2. Check directory
    local pwd
    pwd=$(pwd)
    if [ "$pwd" != "$BASE_DIR" ]; then
        error "Must run from: $BASE_DIR"
    fi
    log "✓ Running from correct directory"

    # 3. Check directories exist
    mkdir -p "$DUMPS_DIR" "$LOGS_DIR" "$TMP_DIR"
    log "✓ Directories created"

    # 4. Check prod credentials file exists
    if [ ! -f "$PROD_CREDENTIALS_FILE" ]; then
        log "WARNING: Production credentials file not found (expected for non-PROD environment)"
    else
        local perms
        perms=$(stat -c "%a" "$PROD_CREDENTIALS_FILE" 2>/dev/null)
        if [ "$perms" != "600" ]; then
            log "WARNING: prod credentials permissions: $perms (expected 600)"
        else
            log "✓ PROD credentials file: permissions OK"
        fi
    fi

    # 5. Check .env.pgsql
    if [ ! -f "$ENV_PGSQL" ]; then
        error "Local PostgreSQL config not found: $ENV_PGSQL"
    fi
    log "✓ Local .env.pgsql found"

    # 6. Check admin password
    if [ -z "$LOCAL_PG_ADMIN_PASSWORD" ]; then
        error "LOCAL_PG_ADMIN_PASSWORD is not set"
    fi
    log "✓ LOCAL_PG_ADMIN_PASSWORD is set"

    # 7. Test local admin connection
    log "Testing PostgreSQL admin connection..."
    export PGPASSWORD="$LOCAL_PG_ADMIN_PASSWORD"
    if ! psql -h "$LOCAL_HOST" -p "$LOCAL_PORT" -U "$LOCAL_PG_ADMIN_USER" -d postgres -c "SELECT 1" &>/dev/null; then
        unset PGPASSWORD
        error "Cannot connect to PostgreSQL as admin user '$LOCAL_PG_ADMIN_USER'"
    fi
    log "✓ PostgreSQL admin connection OK"

    # 8. Create and drop test temp DB
    log "Creating test temporary database..."
    psql -h "$LOCAL_HOST" -p "$LOCAL_PORT" -U "$LOCAL_PG_ADMIN_USER" -d postgres \
        -c "DROP DATABASE IF EXISTS $TEMP_DB_TEST" &>/dev/null
    psql -h "$LOCAL_HOST" -p "$LOCAL_PORT" -U "$LOCAL_PG_ADMIN_USER" -d postgres \
        -c "CREATE DATABASE $TEMP_DB_TEST" &>/dev/null
    log "✓ Test temporary database created"

    log "Dropping test temporary database..."
    psql -h "$LOCAL_HOST" -p "$LOCAL_PORT" -U "$LOCAL_PG_ADMIN_USER" -d postgres \
        -c "DROP DATABASE IF EXISTS $TEMP_DB_TEST" &>/dev/null
    unset PGPASSWORD
    log "✓ Test temporary database dropped"

    # 9. Bash syntax check
    log "Running bash -n syntax check..."
    bash -n "$0"
    log "✓ Shell syntax: OK"

    echo ""
    echo "═════════════════════════════════════════════════"
    echo "  ✓ SELF-TEST PASSED"
    echo "  All checks OK. Ready for real sync."
    echo "═════════════════════════════════════════════════"
    exit 0
}

check_prereqs() {
    log "Checking prerequisites..."
    mkdir -p "$DUMPS_DIR" "$LOGS_DIR" "$TMP_DIR"

    for cmd in psql pg_dump pg_restore php; do
        if ! command -v "$cmd" &>/dev/null; then
            error "Missing required tool: $cmd"
        fi
    done

    local pwd
    pwd=$(pwd)
    if [ "$pwd" != "$BASE_DIR" ]; then
        error "Must run from: $BASE_DIR"
    fi

    if [ ! -f "$PROD_CREDENTIALS_FILE" ]; then
        error "Production credentials not found: $PROD_CREDENTIALS_FILE"
    fi

    local perms
    perms=$(stat -c "%a" "$PROD_CREDENTIALS_FILE" 2>/dev/null)
    if [ "$perms" != "600" ]; then
        log "WARNING: $PROD_CREDENTIALS_FILE has permissions $perms (expected 600)"
    fi
}

load_local_credentials() {
    log "Loading local DB credentials from Laravel env..."
    if [ ! -f "$ENV_PGSQL" ]; then
        error "Local PostgreSQL config not found: $ENV_PGSQL"
    fi

    export LOCAL_DB_USER=$(grep '^DB_USERNAME=' "$ENV_PGSQL" | cut -d '=' -f2)
    export LOCAL_DB_NAME=$(grep '^DB_DATABASE=' "$ENV_PGSQL" | cut -d '=' -f2)
    export LOCAL_DB_PASSWORD=$(grep '^DB_PASSWORD=' "$ENV_PGSQL" | cut -d '=' -f2)

    if [ -z "$LOCAL_DB_USER" ] || [ -z "$LOCAL_DB_NAME" ]; then
        error "Incomplete local DB credentials in $ENV_PGSQL"
    fi
}

load_prod_credentials() {
    log "Loading production credentials..."
    source "$PROD_CREDENTIALS_FILE"

    # Map Laravel Cloud format — set -u compatible with ${var:-default}
    export PROD_HOST="${PROD_DB_HOST:-${DB_HOST:-}}"
    export PROD_PORT="${PROD_DB_PORT:-${DB_PORT:-5432}}"
    export PROD_USER="${PROD_DB_USERNAME:-${DB_USERNAME:-}}"
    export PROD_PASS="${PROD_DB_PASSWORD:-${DB_PASSWORD:-}}"
    export PROD_DB="${PROD_DB_DATABASE:-${DB_DATABASE:-${DB_NAME:-}}}"

    local missing=0
    if [ -z "$PROD_HOST" ]; then log "Missing: HOST"; missing=1; fi
    if [ -z "$PROD_USER" ]; then log "Missing: USERNAME"; missing=1; fi
    if [ -z "$PROD_PASS" ]; then log "Missing: PASSWORD"; missing=1; fi
    if [ -z "$PROD_DB" ]; then log "Missing: DATABASE"; missing=1; fi

    if [ "$missing" -eq 1 ]; then
        error "Incomplete production credentials in $PROD_CREDENTIALS_FILE"
    fi
}

check_local_db() {
    log "Verifying local database connectivity..."
    export PGPASSWORD="$LOCAL_DB_PASSWORD"
    if ! psql -h "$LOCAL_HOST" -p "$LOCAL_PORT" -U "$LOCAL_DB_USER" -d "$LOCAL_DB_NAME" -c "SELECT 1" &>/dev/null; then
        unset PGPASSWORD
        error "Cannot connect to local database: $LOCAL_DB_NAME"
    fi
    unset PGPASSWORD
}

check_main_org() {
    log "Verifying default backfill organization exists locally..."
    export PGPASSWORD="$LOCAL_DB_PASSWORD"
    local main_exists
    main_exists=$(psql -h "$LOCAL_HOST" -p "$LOCAL_PORT" -U "$LOCAL_DB_USER" -d "$LOCAL_DB_NAME" -tAc "SELECT 1 FROM organizations WHERE slug = 'main' OR is_default = true LIMIT 1")
    unset PGPASSWORD

    if [ "$main_exists" != "1" ]; then
        log "No default organization found. Creating 'main' automatically..."
        export PGPASSWORD="$LOCAL_DB_PASSWORD"
        local main_id
        main_id=$(psql -h "$LOCAL_HOST" -p "$LOCAL_PORT" -U "$LOCAL_DB_USER" -d "$LOCAL_DB_NAME" -tAc "
            INSERT INTO organizations (id, name, slug, is_active, is_default, created_at, updated_at)
            VALUES (gen_random_uuid(), 'Main', 'main', true, true, NOW(), NOW())
            RETURNING id;
        ")
        unset PGPASSWORD
        log "✓ Organization 'main' created (ID: $main_id)"
    fi
}

dump_prod() {
    local dump_file="$DUMPS_DIR/production_$TIMESTAMP.dump"
    echo "$dump_file" >&2
    log "Dumping PROD database to: $dump_file"

    export PGPASSWORD="$PROD_PASS"
    pg_dump \
        --host="$PROD_HOST" \
        --port="$PROD_PORT" \
        --username="$PROD_USER" \
        --dbname="$PROD_DB" \
        --format=custom \
        --no-owner \
        --no-acl \
        --verbose \
        --file="$dump_file"
    unset PROD_PASS

    log "PROD dump completed: $(du -h "$dump_file" | cut -f1)"
    echo "$dump_file"
}

recreate_temp_db() {
    log "Recreating temporary database: $TEMP_DB"

    if [ -z "$LOCAL_PG_ADMIN_PASSWORD" ]; then
        error "LOCAL_PG_ADMIN_PASSWORD is not set. Required for temp DB management."
    fi

    export PGPASSWORD="$LOCAL_PG_ADMIN_PASSWORD"

    # Terminate existing connections to temp DB
    psql -h "$LOCAL_HOST" -p "$LOCAL_PORT" -U "$LOCAL_PG_ADMIN_USER" -d postgres \
        -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$TEMP_DB' AND pid <> pg_backend_pid();" 2>/dev/null || true

    # Drop temp DB
    psql -h "$LOCAL_HOST" -p "$LOCAL_PORT" -U "$LOCAL_PG_ADMIN_USER" -d postgres \
        -c "DROP DATABASE IF EXISTS $TEMP_DB;"

    # Create temp DB
    psql -h "$LOCAL_HOST" -p "$LOCAL_PORT" -U "$LOCAL_PG_ADMIN_USER" -d postgres \
        -c "CREATE DATABASE $TEMP_DB;"

    # Grant access to Laravel user so PHP ETL can read tables
    psql -h "$LOCAL_HOST" -p "$LOCAL_PORT" -U "$LOCAL_PG_ADMIN_USER" -d "$TEMP_DB" \
        -c "GRANT USAGE ON SCHEMA public TO $LOCAL_DB_USER;"
    psql -h "$LOCAL_HOST" -p "$LOCAL_PORT" -U "$LOCAL_PG_ADMIN_USER" -d "$TEMP_DB" \
        -c "GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO $LOCAL_DB_USER;"
    psql -h "$LOCAL_HOST" -p "$LOCAL_PORT" -U "$LOCAL_PG_ADMIN_USER" -d "$TEMP_DB" \
        -c "GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO $LOCAL_DB_USER;"
    psql -h "$LOCAL_HOST" -p "$LOCAL_PORT" -U "$LOCAL_PG_ADMIN_USER" -d "$TEMP_DB" \
        -c "ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO $LOCAL_DB_USER;"
    psql -h "$LOCAL_HOST" -p "$LOCAL_PORT" -U "$LOCAL_PG_ADMIN_USER" -d "$TEMP_DB" \
        -c "ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO $LOCAL_DB_USER;"

    unset PGPASSWORD

    log "Temporary database created with Laravel user access"
}

restore_to_temp() {
    local dump_file="$1"
    log "Restoring PROD dump to temporary database..."

    if [ -z "$LOCAL_PG_ADMIN_PASSWORD" ]; then
        error "LOCAL_PG_ADMIN_PASSWORD is not set. Required for temp DB restore."
    fi

    export PGPASSWORD="$LOCAL_PG_ADMIN_PASSWORD"
    pg_restore \
        --host="$LOCAL_HOST" \
        --port="$LOCAL_PORT" \
        --username="$LOCAL_PG_ADMIN_USER" \
        --dbname="$TEMP_DB" \
        --no-owner \
        --no-acl \
        --verbose \
        "$dump_file"

    # Re-grant post-restore: pg_restore creates tables/sequences as postgres,
    # and the initial grants in recreate_temp_db ran before any objects existed.
    # Without re-granting, bouclepro can't see FK constraints in information_schema.
    log "Re-granting privileges on restored objects..."
    psql -h "$LOCAL_HOST" -p "$LOCAL_PORT" -U "$LOCAL_PG_ADMIN_USER" -d "$TEMP_DB" \
        -c "GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO $LOCAL_DB_USER;"
    psql -h "$LOCAL_HOST" -p "$LOCAL_PORT" -U "$LOCAL_PG_ADMIN_USER" -d "$TEMP_DB" \
        -c "GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO $LOCAL_DB_USER;"

    unset PGPASSWORD

    log "Restore and re-grant completed"
}

run_php_etl() {
    log "Running PHP ETL script..."
    cd "$BASE_DIR"
    LOCAL_DB_PASSWORD="$LOCAL_DB_PASSWORD" php "$PHP_SCRIPT" \
        --local-host="$LOCAL_HOST" \
        --local-port="$LOCAL_PORT" \
        --local-db="$LOCAL_DB_NAME" \
        --local-user="$LOCAL_DB_USER" \
        --temp-db="$TEMP_DB"
}

run_seeders() {
    log "Running seeders..."

    cd "$BASE_DIR"
    php artisan db:seed --class=LegacyDataOrganizationSeeder --force 2>&1 || log "WARNING: LegacyDataOrganizationSeeder failed (schema may be ahead of local migrations)"
    php artisan db:seed --class=QaAccountsSeeder --force 2>&1 || log "WARNING: QaAccountsSeeder failed"
    php artisan optimize:clear 2>&1 || log "WARNING: optimize:clear failed"
}

validate_sync() {
    log "Validating sync results..."
    export PGPASSWORD="$LOCAL_DB_PASSWORD"

    # Check users without organization_id
    local null_org_users
    null_org_users=$(psql -h "$LOCAL_HOST" -p "$LOCAL_PORT" -U "$LOCAL_DB_USER" -d "$LOCAL_DB_NAME" -tAc "SELECT COUNT(*) FROM users WHERE organization_id IS NULL AND email IS NOT NULL" 2>/dev/null || echo "N/A")
    log "Users without organization_id (with non-null email): $null_org_users"

    # Check users without community_id (if column exists)
    local has_community_id
    has_community_id=$(psql -h "$LOCAL_HOST" -p "$LOCAL_PORT" -U "$LOCAL_DB_USER" -d "$LOCAL_DB_NAME" -tAc "SELECT 1 FROM information_schema.columns WHERE table_name = 'users' AND column_name = 'community_id' LIMIT 1" 2>/dev/null || echo "0")
    if [ "$has_community_id" = "1" ]; then
        local null_community_users
        null_community_users=$(psql -h "$LOCAL_HOST" -p "$LOCAL_PORT" -U "$LOCAL_DB_USER" -d "$LOCAL_DB_NAME" -tAc "SELECT COUNT(*) FROM users WHERE community_id IS NULL AND email IS NOT NULL" 2>/dev/null || echo "N/A")
        log "Users without community_id (with non-null email): $null_community_users"
    fi

    # Check tables exist
    for table in loops loop_members loop_messages; do
        local exists
        exists=$(psql -h "$LOCAL_HOST" -p "$LOCAL_PORT" -U "$LOCAL_DB_USER" -d "$LOCAL_DB_NAME" -tAc "SELECT 1 FROM information_schema.tables WHERE table_name = '$table' LIMIT 1")
        if [ "$exists" = "1" ]; then
            local count
            count=$(psql -h "$LOCAL_HOST" -p "$LOCAL_PORT" -U "$LOCAL_DB_USER" -d "$LOCAL_DB_NAME" -tAc "SELECT COUNT(*) FROM $table")
            log "✓ $table exists: $count rows"
        else
            log "WARNING: $table missing"
        fi
    done

    # Count key tables
    echo "" >&2
    log "Database counters:"
    for table in users services service_requests transactions messages blog_posts referrals referral_rewards; do
        local count
        count=$(psql -h "$LOCAL_HOST" -p "$LOCAL_PORT" -U "$LOCAL_DB_USER" -d "$LOCAL_DB_NAME" -tAc "SELECT COUNT(*) FROM $table" 2>/dev/null || echo "0")
        log "  $table: $count"
    done

    unset PGPASSWORD
}

cleanup_temp_db() {
    if [ "$ETL_SUCCESS" != true ]; then
        log "ETL did not complete successfully. Preserving $TEMP_DB for diagnostics."
        return 0
    fi

    log "Cleaning up temporary database..."

    if [ -z "$LOCAL_PG_ADMIN_PASSWORD" ]; then
        log "WARNING: LOCAL_PG_ADMIN_PASSWORD not set, cannot delete temp DB."
        return 0
    fi

    export PGPASSWORD="$LOCAL_PG_ADMIN_PASSWORD"
    psql -h "$LOCAL_HOST" -p "$LOCAL_PORT" -U "$LOCAL_PG_ADMIN_USER" -d postgres \
        -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$TEMP_DB' AND pid <> pg_backend_pid();" 2>/dev/null || true
    psql -h "$LOCAL_HOST" -p "$LOCAL_PORT" -U "$LOCAL_PG_ADMIN_USER" -d postgres \
        -c "DROP DATABASE IF EXISTS $TEMP_DB;"
    unset PGPASSWORD

    log "✓ Temporary database deleted"
}

# =========================================================
# MAIN
# =========================================================
main() {
    # Handle --self-test
    if [ "${1:-}" = "--self-test" ]; then
        self_test
    fi

    echo ""
    echo "═════════════════════════════════════════════════"
    echo "  PROD → LOCAL SYNC (Non-destructive)"
    echo "  Preserves local schema, syncs data only"
    echo "═════════════════════════════════════════════════"
    echo ""

    # Phase 1: Prerequisites
    log "Phase 1/9 — Prerequisites check"
    check_prereqs
    log "✓ Phase 1/9 completed"

    # Phase 2: Load credentials
    log "Phase 2/9 — Load credentials"
    load_local_credentials
    load_prod_credentials
    log "✓ Phase 2/9 completed"

    # Phase 3: Verify local DB
    log "Phase 3/9 — Verify local DB"
    check_local_db
    check_main_org
    log "✓ Phase 3/9 completed"

    # Phase 4: Dump PROD
    log "Phase 4/9 — Dump PROD database"
    local dump_file
    dump_file=$(dump_prod)
    log "✓ Phase 4/9 completed"

    # Phase 5: Recreate temp DB
    log "Phase 5/9 — Recreate temporary database"
    recreate_temp_db
    log "✓ Phase 5/9 completed"

    # Phase 6: Restore to temp
    log "Phase 6/9 — Restore PROD dump to temporary DB"
    restore_to_temp "$dump_file"
    log "✓ Phase 6/9 completed"

    # Phase 7: Run ETL
    log "Phase 7/9 — Run PHP ETL (temp → local)"
    if ! run_php_etl; then
        error "PHP ETL failed. Temporary database $TEMP_DB preserved for diagnostics."
    fi
    ETL_SUCCESS=true
    log "✓ Phase 7/9 completed"

    # Phase 8: Seeders
    log "Phase 8/9 — Run seeders and clear cache"
    run_seeders
    log "✓ Phase 8/9 completed"

    # Phase 9: Validation
    log "Phase 9/9 — Validate sync results"
    validate_sync 2>&1 | tee "$LOG_FILE" || true
    log "✓ Phase 9/9 completed"

    # Cleanup
    cleanup_temp_db

    echo ""
    echo "═════════════════════════════════════════════════"
    echo "  ✓ SYNC COMPLETE"
    echo "  Report: $LOG_FILE"
    echo "═════════════════════════════════════════════════"
    echo ""
}

main "$@"
