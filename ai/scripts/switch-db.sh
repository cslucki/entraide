#!/bin/bash
# =========================================================
# SWITCH DATABASE ENVIRONMENT
# =========================================================
# Usage:
#   ./switch-db.sh pgsql   → switch to PostgreSQL (.env.pgsql → .env)
#   ./switch-db.sh sqlite  → switch to SQLite (.env.example → .env)
#   ./switch-db.sh status  → show current DB connection
# =========================================================

set -e

BASE_DIR="/home/cyril/claude-code/sites/test.laravel"

case "${1:-}" in
  pgsql)
    echo "→ Switching to PostgreSQL..."
    cp "$BASE_DIR/.env.pgsql" "$BASE_DIR/.env"
    echo "✓ .env updated from .env.pgsql"
    echo "→ Run: php artisan migrate:fresh --seed"
    ;;

  sqlite)
    echo "→ Switching to SQLite..."
    cp "$BASE_DIR/.env.example" "$BASE_DIR/.env"
    echo "✓ .env updated from .env.example"
    echo "→ Run: php artisan migrate:fresh --seed"
    ;;

  status)
    echo "Current DB_CONNECTION:"
    grep "^DB_CONNECTION" "$BASE_DIR/.env" 2>/dev/null || echo "  (not set)"
    echo ""
    echo "DB_DATABASE:"
    grep "^DB_DATABASE" "$BASE_DIR/.env" 2>/dev/null || echo "  (not set)"
    ;;

  *)
    echo "Usage: $0 {pgsql|sqlite|status}"
    echo ""
    echo "  pgsql   Switch to PostgreSQL (.env.pgsql → .env)"
    echo "  sqlite  Switch to SQLite (.env.example → .env)"
    echo "  status  Show current DB connection"
    exit 1
    ;;
esac
