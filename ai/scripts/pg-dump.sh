#!/bin/bash
# =========================================================
# POSTGRESQL DUMP / IMPORT / EXPORT
# =========================================================
# Production sync workflow for Laravel Cloud
# =========================================================
# Usage:
#   ./pg-dump.sh dump [file]        → Export local PostgreSQL to file
#   ./pg-dump.sh import <file>      → Import dump into local PostgreSQL
#   ./pg-dump.sh prod-dump          → Instructions for production dump
#   ./pg-dump.sh schema-only [file] → Export schema only (no data)
#   ./pg-dump.sh data-only [file]   → Export data only (no schema)
#   ./pg-dump.sh list               → List available dumps
#   ./pg-dump.sh prod-dump [file]  → Export production PostgreSQL to file (manual creds)
#   ./pg-dump.sh prod-mirror       → Full prod → local mirror: dump + import + migrate + cache
#   ./pg-dump.sh reset              → Import latest dump + migrate + cache clear
# =========================================================

set -e

BASE_DIR="/home/cyril/claude-code/sites/test.laravel"
DUMPS_DIR="$BASE_DIR/storage/app/dumps"
ENV_FILE="$BASE_DIR/.env"
ENV_PGSQL="$BASE_DIR/.env.pgsql"

PG_USER="bouclepro"
PG_DB="bouclepro"
PG_HOST="127.0.0.1"
PG_PORT="5432"

# Read password from .env.pgsql (single source of truth)
if [ -f "$ENV_PGSQL" ]; then
    export PGPASSWORD=$(grep '^DB_PASSWORD=' "$ENV_PGSQL" | head -1 | cut -d '=' -f2)
else
    echo "Error: $ENV_PGSQL not found — cannot determine PostgreSQL password."
    exit 1
fi

mkdir -p "$DUMPS_DIR"

timestamp() {
    date '+%Y-%m-%d_%H-%M-%S'
}

# -------------------------------------------------------
# Prerequisite checks
# -------------------------------------------------------
check_prereqs() {
    for cmd in pg_dump pg_restore psql; do
        if ! command -v "$cmd" &>/dev/null; then
            echo "Error: '$cmd' not found. Install PostgreSQL client tools."
            exit 1
        fi
    done
}

check_pg_connection() {
    if ! psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$PG_DB" -c "SELECT 1" &>/dev/null; then
        echo "Error: Cannot connect to PostgreSQL at $PG_HOST:$PG_PORT as $PG_USER."
        echo "  Is the PostgreSQL service running?"
        echo "  Try: sudo service postgresql start"
        exit 1
    fi
}

check_runtime_env() {
    local current_db
    current_db=$(grep '^DB_CONNECTION=' "$ENV_FILE" 2>/dev/null | cut -d '=' -f2)
    if [ "$current_db" != "pgsql" ]; then
        echo "Warning: Current .env has DB_CONNECTION=$current_db (not pgsql)."
        echo "  Run './ai/scripts/switch-db.sh pgsql' first."
        echo ""
        read -rp "Continue anyway? (y/N): " confirm
        if [ "$confirm" != "y" ]; then
            echo "Aborted."
            exit 1
        fi
    fi
}

confirm_destructive() {
    local action="$1"
    local target="$2"
    echo "⚠️  DESTRUCTIVE ACTION: $action"
    echo "  Target: $target"
    echo "  This will REPLACE existing data."
    echo ""
    read -rp "Are you sure? (y/N): " confirm
    if [ "$confirm" != "y" ]; then
        echo "Aborted."
        exit 1
    fi
}

case "${1:-}" in
  dump)
    check_prereqs
    check_pg_connection
    check_runtime_env
    FILE="${2:-${DUMPS_DIR}/bouclepro_$(timestamp).sql}"
    echo "→ Dumping PostgreSQL database '$PG_DB' to: $FILE"
    pg_dump \
      --host="$PG_HOST" \
      --port="$PG_PORT" \
      --username="$PG_USER" \
      --dbname="$PG_DB" \
      --format=custom \
      --verbose \
      --file="$FILE"
    echo "✓ Dump completed: $FILE"
    ls -lh "$FILE"
    ;;

  import)
    check_prereqs
    check_pg_connection
    check_runtime_env
    FILE="${2:-}"
    if [ -z "$FILE" ]; then
      echo "Error: missing file argument"
      echo "Usage: $0 import <dump-file>"
      exit 1
    fi
    if [ ! -f "$FILE" ]; then
      echo "Error: file not found: $FILE"
      exit 1
    fi
    confirm_destructive "Import database dump" "$FILE"
    echo "→ Importing dump into PostgreSQL database '$PG_DB' from: $FILE"
    pg_restore \
      --host="$PG_HOST" \
      --port="$PG_PORT" \
      --username="$PG_USER" \
      --dbname="$PG_DB" \
      --clean \
      --if-exists \
      --verbose \
      "$FILE"
    echo "✓ Import completed"
    echo "→ Running pending migrations..."
    php artisan migrate --force
    echo "✓ Migrations up to date"
    echo "→ Clearing application cache..."
    php artisan optimize:clear
    echo "✓ Cache cleared"
    ;;

  schema-only)
    check_prereqs
    check_pg_connection
    check_runtime_env
    FILE="${2:-${DUMPS_DIR}/bouclepro_schema_$(timestamp).sql}"
    echo "→ Dumping schema only to: $FILE"
    pg_dump \
      --host="$PG_HOST" \
      --port="$PG_PORT" \
      --username="$PG_USER" \
      --dbname="$PG_DB" \
      --schema-only \
      --format=custom \
      --file="$FILE"
    echo "✓ Schema dump completed: $FILE"
    ;;

  data-only)
    check_prereqs
    check_pg_connection
    check_runtime_env
    FILE="${2:-${DUMPS_DIR}/bouclepro_data_$(timestamp).sql}"
    echo "→ Dumping data only to: $FILE"
    pg_dump \
      --host="$PG_HOST" \
      --port="$PG_PORT" \
      --username="$PG_USER" \
      --dbname="$PG_DB" \
      --data-only \
      --format=custom \
      --file="$FILE"
    echo "✓ Data dump completed: $FILE"
    ;;

  list)
    echo "Available dumps in $DUMPS_DIR:"
    ls -lhS "$DUMPS_DIR"/*.sql 2>/dev/null || echo "  (no dumps found)"
    echo ""
    echo "Dumps directory is gitignored (see .gitignore)."
    ;;

  reset)
    check_prereqs
    check_pg_connection
    check_runtime_env
    LATEST=$(ls -t "$DUMPS_DIR"/*.sql 2>/dev/null | head -1)
    if [ -z "$LATEST" ]; then
      echo "Error: no dump files found in $DUMPS_DIR"
      echo "  Create one with: $0 dump"
      exit 1
    fi
    echo "→ Latest dump: $(basename "$LATEST") ($(du -h "$LATEST" | cut -f1))"
    confirm_destructive "Reset local database from latest dump" "$LATEST"
    echo "→ Importing..."
    pg_restore \
      --host="$PG_HOST" \
      --port="$PG_PORT" \
      --username="$PG_USER" \
      --dbname="$PG_DB" \
      --clean \
      --if-exists \
      "$LATEST"
    echo "✓ Import completed"
    echo "→ Running pending migrations..."
    php artisan migrate --force
    echo "✓ Migrations up to date"
    echo "→ Clearing application cache..."
    php artisan optimize:clear
    echo "✓ Cache cleared"
    echo ""
    echo "✓ Local database reset from: $(basename "$LATEST")"
    ;;

  prod-dump)
    check_prereqs
    FILE="${2:-${DUMPS_DIR}/production_$(timestamp).sql}"
    echo "→ Production dump (Laravel Cloud)"
    echo ""
    echo "This command creates a production database dump for local mirroring."
    echo ""
    echo "Mode: MANUAL (enter credentials from Laravel Cloud dashboard)"
    echo ""
    echo "Production credentials can be obtained via:"
    echo "   php artisan cloud:db:show"
    echo "  (or from Laravel Cloud Dashboard > Database > Connection Details)"
    echo ""
    echo "---"
    echo ""
    read -rp "Production host: " PROD_HOST
    read -rp "Production port [5432]: " PROD_PORT
    PROD_PORT="${PROD_PORT:-5432}"
    read -rp "Production username: " PROD_USER
    read -rsp "Production password: " PROD_PASS
    echo ""
    read -rp "Production database name: " PROD_DB
    echo ""
    confirm_destructive "Dump production database" "$PROD_HOST:$PROD_PORT/$PROD_DB"
    echo ""
    echo "→ Dumping production database to: $FILE"
    PGPASSWORD="$PROD_PASS" pg_dump \
      --host="$PROD_HOST" \
      --port="$PROD_PORT" \
      --username="$PROD_USER" \
      --dbname="$PROD_DB" \
      --format=custom \
      --no-owner \
      --verbose \
      --file="$FILE"
    unset PROD_PASS
    echo "✓ Production dump completed: $FILE"
    ls -lh "$FILE"
    echo ""
    echo "⚠️  NEVER commit production dumps to the repository."
    echo "   The dumps directory is already gitignored (see .gitignore)."
    ;;

  prod-mirror)
    echo "═════════════════════════════════════════════════"
    echo "  PRODUCTION → LOCAL MIRROR WORKFLOW"
    echo "═════════════════════════════════════════════════"
    echo ""
    check_prereqs
    check_runtime_env
    echo ""
    echo "Phase 1/4 — Dump production database"
    echo "──────────────────────────────────────"
    echo ""
    echo "Production credentials can be obtained via:"
    echo "  - Laravel Cloud Dashboard > Database > Connection Details"
    echo "  - Or: php artisan cloud:db:show"
    echo ""
    read -rp "Production host: " PROD_HOST
    read -rp "Production port [5432]: " PROD_PORT
    PROD_PORT="${PROD_PORT:-5432}"
    read -rp "Production username: " PROD_USER
    read -rsp "Production password: " PROD_PASS
    echo ""
    read -rp "Production database name: " PROD_DB
    MIRROR_FILE="${DUMPS_DIR}/production_$(timestamp).sql"
    echo ""
    echo "→ Dumping production DB to: $MIRROR_FILE"
    PGPASSWORD="$PROD_PASS" pg_dump \
      --host="$PROD_HOST" \
      --port="$PROD_PORT" \
      --username="$PROD_USER" \
      --dbname="$PROD_DB" \
      --format=custom \
      --no-owner \
      --verbose \
      --file="$MIRROR_FILE"
    unset PROD_PASS
    echo "✓ Phase 1/4 — Dump completed"
    echo ""
    echo "Phase 2/4 — Import into local PostgreSQL"
    echo "─────────────────────────────────────────"
    confirm_destructive "Import production dump into LOCAL database" "$PG_DB@$PG_HOST:$PG_PORT"
    pg_restore \
      --host="$PG_HOST" \
      --port="$PG_PORT" \
      --username="$PG_USER" \
      --dbname="$PG_DB" \
      --clean \
      --if-exists \
      --verbose \
      "$MIRROR_FILE"
    echo "✓ Phase 2/4 — Import completed"
    echo ""
    echo "Phase 3/4 — Run pending migrations"
    echo "─────────────────────────────────────"
    php artisan migrate --force
    echo "✓ Phase 3/4 — Migrations up to date"
    echo ""
    echo "Phase 4/4 — Clear application cache"
    echo "────────────────────────────────────"
    php artisan optimize:clear
    echo "✓ Phase 4/4 — Cache cleared"
    echo ""
    echo "═════════════════════════════════════════════════"
    echo "  ✓ MIRROR COMPLETE"
    echo "  Source: $PROD_HOST/$PROD_DB"
    echo "  Local:  $PG_HOST:$PG_PORT/$PG_DB"
    echo "  Dump:   $(basename "$MIRROR_FILE")"
    echo "═════════════════════════════════════════════════"
    echo ""
    echo "Next steps (CODE):"
    echo "  1. ./ai/scripts/switch-db.sh pgsql"
    echo "  2. php artisan test"
    echo "  3. npx playwright test"
    echo "  4. Validate runtime parity"
    ;;

  *)
    echo "Usage: $0 {dump|import|schema-only|data-only|list|prod-dump|prod-mirror|reset} [file]"
    echo ""
    echo "Commands:"
    echo "  dump [file]         Export full database to custom-format dump"
    echo "  import <file>       Restore custom-format dump (--clean, re-create)"
    echo "  schema-only [file]  Export schema without data"
    echo "  data-only [file]    Export data without schema"
    echo "  list                List available dump files"
    echo "  reset               Import latest dump + migrate + cache clear"
    echo "  prod-dump [file]    Dump production Laravel Cloud database (manual creds)"
    echo "  prod-mirror         Full prod → local mirror: dump + import + migrate + cache"
    exit 1
    ;;
esac
