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
    echo "→ Production dump (Laravel Cloud)"
    echo ""
    echo "Laravel Cloud PostgreSQL credentials are available via:"
    echo "  php artisan cloud:db:show"
    echo ""
    echo "Typical production dump workflow:"
    echo ""
    echo "  1. Get production connection details:"
    echo "     php artisan cloud:db:show"
    echo ""
    echo "  2. Dump production database:"
    echo "     pg_dump --host=<prod-host> --port=5432 \\"
    echo "       --username=<prod-user> --dbname=<prod-db> \\"
    echo "       --format=custom --no-owner \\"
    echo "       --file=${DUMPS_DIR}/production_$(timestamp).sql"
    echo ""
    echo "  3. Import into local:"
    echo "     $0 import ${DUMPS_DIR}/production_<timestamp>.sql"
    echo ""
    echo "  4. Or use reset for full workflow:"
    echo "     $0 reset"
    echo ""
    echo "⚠️  NEVER commit production dumps to the repository."
    echo "   The dumps directory is already gitignored (see .gitignore)."
    ;;

  *)
    echo "Usage: $0 {dump|import|schema-only|data-only|list|prod-dump|reset} [file]"
    echo ""
    echo "Commands:"
    echo "  dump [file]         Export full database to custom-format dump"
    echo "  import <file>       Restore custom-format dump (--clean, re-create)"
    echo "  schema-only [file]  Export schema without data"
    echo "  data-only [file]    Export data without schema"
    echo "  list                List available dump files"
    echo "  reset               Import latest dump + migrate + cache clear"
    echo "  prod-dump           Instructions for Laravel Cloud production dump"
    exit 1
    ;;
esac
