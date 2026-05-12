#!/bin/bash
# =========================================================
# SWITCH DATABASE ENVIRONMENT
# =========================================================
# Usage:
#   ./switch-db.sh sqlite  → switch to SQLite (.env.sqlite → .env)
#   ./switch-db.sh pgsql   → switch to PostgreSQL (.env.pgsql → .env)
#   ./switch-db.sh status  → show current DB connection
# =========================================================

set -e

BASE_DIR="/home/cyril/claude-code/sites/test.laravel"

check_uncommitted() {
    if ! git -C "$BASE_DIR" diff --quiet --exit-code 2>/dev/null; then
        echo "⚠️  Warning: you have uncommitted changes."
        echo "   Switching databases may cause confusion."
        echo ""
        read -rp "Continue? (y/N): " confirm
        if [ "$confirm" != "y" ]; then
            echo "Aborted."
            exit 1
        fi
    fi
}

case "${1:-}" in
  sqlite)
    SRC="$BASE_DIR/.env.sqlite"
    if [ ! -f "$SRC" ]; then
        echo "Error: $SRC not found."
        echo "  Create it from .env.example or restore from backup."
        exit 1
    fi
    check_uncommitted
    cp "$BASE_DIR/.env" "$BASE_DIR/.env.bak" 2>/dev/null || true
    echo "→ Backed up current .env to .env.bak"
    echo "→ Switching to SQLite..."
    cp "$SRC" "$BASE_DIR/.env"
    echo "✓ .env updated from .env.sqlite"
    echo "→ Clearing application cache..."
    php artisan optimize:clear 2>/dev/null || true
    echo "✓ Cache cleared"
    echo ""
    echo "Next step: php artisan migrate:fresh --seed"
    ;;

  pgsql)
    SRC="$BASE_DIR/.env.pgsql"
    if [ ! -f "$SRC" ]; then
        echo "Error: $SRC not found."
        echo "  Create it from .env.example or restore from backup."
        exit 1
    fi
    check_uncommitted
    cp "$BASE_DIR/.env" "$BASE_DIR/.env.bak" 2>/dev/null || true
    echo "→ Backed up current .env to .env.bak"
    echo "→ Switching to PostgreSQL..."
    cp "$SRC" "$BASE_DIR/.env"
    echo "✓ .env updated from .env.pgsql"
    echo "→ Clearing application cache..."
    php artisan optimize:clear 2>/dev/null || true
    echo "✓ Cache cleared"
    echo ""
    echo "Next step: php artisan migrate:fresh --seed"
    ;;

  status)
    echo "=== Current Environment ==="
    echo ""
    echo "DB_CONNECTION:"
    grep "^DB_CONNECTION" "$BASE_DIR/.env" 2>/dev/null || echo "  (not set)"
    echo ""
    echo "DB_DATABASE:"
    grep "^DB_DATABASE" "$BASE_DIR/.env" 2>/dev/null || echo "  (not set)"
    echo ""
    DB_CONN=$(grep "^DB_CONNECTION" "$BASE_DIR/.env" 2>/dev/null | cut -d '=' -f2)
    if [ "$DB_CONN" = "pgsql" ]; then
        echo "PostgreSQL connectivity:"
        PGPASSWORD=$(grep '^DB_PASSWORD=' "$BASE_DIR/.env.pgsql" 2>/dev/null | cut -d '=' -f2)
        PG_HOST=$(grep '^DB_HOST=' "$BASE_DIR/.env.pgsql" 2>/dev/null | cut -d '=' -f2)
        PG_PORT=$(grep '^DB_PORT=' "$BASE_DIR/.env.pgsql" 2>/dev/null | cut -d '=' -f2)
        PG_USER=$(grep '^DB_USERNAME=' "$BASE_DIR/.env.pgsql" 2>/dev/null | cut -d '=' -f2)
        PG_DB=$(grep '^DB_DATABASE=' "$BASE_DIR/.env.pgsql" 2>/dev/null | cut -d '=' -f2)
        if [ -n "$PGPASSWORD" ] && [ -n "$PG_HOST" ]; then
            export PGPASSWORD
            if psql -h "$PG_HOST" -p "${PG_PORT:-5432}" -U "$PG_USER" -d "$PG_DB" -c "SELECT 1" &>/dev/null; then
                echo "  ✅ PostgreSQL is reachable at $PG_HOST:${PG_PORT:-5432}"
            else
                echo "  ❌ PostgreSQL is NOT reachable at $PG_HOST:${PG_PORT:-5432}"
                echo "     Is the PostgreSQL service running?"
            fi
        else
            echo "  ⚠️  Could not determine PostgreSQL credentials"
        fi
    elif [ "$DB_CONN" = "sqlite" ]; then
        echo "SQLite: no server check needed (file-based)"
    fi
    ;;

  *)
    echo "Usage: $0 {sqlite|pgsql|status}"
    echo ""
    echo "  sqlite  Switch to SQLite (.env.sqlite → .env)"
    echo "  pgsql   Switch to PostgreSQL (.env.pgsql → .env)"
    echo "  status  Show current DB connection and connectivity"
    exit 1
    ;;
esac
