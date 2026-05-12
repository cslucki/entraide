#!/bin/bash
# =========================================================
# POSTGRESQL DUMP / IMPORT / EXPORT
# =========================================================
# Production sync workflow for Laravel Cloud
# =========================================================
# Usage:
#   ./pg-dump.sh dump [file]        → Export local PostgreSQL to file
#   ./pg-dump.sh import <file>      → Import dump into local PostgreSQL
#   ./pg-dump.sh prod-dump <file>   → Dump production (via pg_dump over SSH or cloud CLI)
#   ./pg-dump.sh schema-only [file] → Export schema only (no data)
#   ./pg-dump.sh data-only [file]   → Export data only (no schema)
#   ./pg-dump.sh list               → List available dumps
# =========================================================

set -e

BASE_DIR="/home/cyril/claude-code/sites/test.laravel"
DUMPS_DIR="$BASE_DIR/storage/app/dumps"
PG_USER="bouclepro"
PG_DB="bouclepro"
PG_HOST="127.0.0.1"
PG_PORT="5432"
export PGPASSWORD="bouclepro_local_2026"

mkdir -p "$DUMPS_DIR"

timestamp() {
    date '+%Y-%m-%d_%H-%M-%S'
}

case "${1:-}" in
  dump)
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
    ;;

  schema-only)
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
    echo "  4. Run pending migrations:"
    echo "     php artisan migrate"
    echo ""
    echo "⚠️  NEVER commit production dumps to the repository."
    echo "   Add them to .gitignore or keep outside the repo."
    ;;

  *)
    echo "Usage: $0 {dump|import|schema-only|data-only|list|prod-dump} [file]"
    echo ""
    echo "Commands:"
    echo "  dump [file]         Export full database to custom-format dump"
    echo "  import <file>       Restore custom-format dump (--clean, re-create)"
    echo "  schema-only [file]  Export schema without data"
    echo "  data-only [file]    Export data without schema"
    echo "  list                List available dump files"
    echo "  prod-dump           Instructions for Laravel Cloud production dump"
    exit 1
    ;;
esac
