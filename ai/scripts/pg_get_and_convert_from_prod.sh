#!/bin/bash
# =========================================================
# PG GET & CONVERT FROM PROD
# =========================================================
# ATTENTION : CE SCRIPT EST OBSOLÈTE / CASSÉ
#
# Raison : référence le fichier transform qui n'existe plus :
#   .ai-local/orchestrator/scripts-orchestrator/pg-sync-transform.php
#
# Le workflow de remplacement est :
#   _bash_cyril/synchro_pgsql-avant-migration/sync-prod-to-local.sh
#
# Ce script est conservé pour référence historique uniquement.
# Ne pas lancer.
#
# Usage (non fonctionnel) :
#   ./pg_get_and_convert_from_prod.sh
#
# =========================================================

set -e

BASE_DIR="/home/cyril/claude-code/sites/test.laravel"
ENV_FILE="$BASE_DIR/.env"
ENV_PGSQL="$BASE_DIR/.env.pgsql"
TRANSFORM_SCRIPT="$BASE_DIR/.ai-local/orchestrator/scripts-orchestrator/pg-sync-transform.php"

PG_USER="bouclepro"
PG_DB="bouclepro"
PG_HOST="127.0.0.1"
PG_PORT="5432"

PROD_CREDENTIALS_FILE="/home/cyril/.config/bouclepro/prod-db.env"

# -------------------------------------------------------
# Helpers
# -------------------------------------------------------
check_prereqs() {
    for cmd in php psql; do
        if ! command -v "$cmd" &>/dev/null; then
            echo "Error: '$cmd' not found."
            exit 1
        fi
    done
}

check_pg_connection() {
    if ! psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$PG_DB" -c "SELECT 1" &>/dev/null; then
        echo "Error: Cannot connect to PostgreSQL at $PG_HOST:$PG_PORT as $PG_USER."
        echo "  Is the PostgreSQL service running?"
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
    echo "⚠️  DESTRUCTIVE ACTION: $1"
    echo "  Target: $2"
    echo "  This will REPLACE all local DATA with production data."
    echo "  Schema 2 structure (loops, referrals, etc.) is preserved."
    echo ""
    read -rp "Are you sure? (y/N): " confirm
    if [ "$confirm" != "y" ]; then
        echo "Aborted."
        exit 1
    fi
}

# -------------------------------------------------------
# Production credentials
# -------------------------------------------------------
load_prod_credentials() {
    if [ ! -f "$PROD_CREDENTIALS_FILE" ]; then
        echo "Error: Production credentials not found at $PROD_CREDENTIALS_FILE"
        exit 1
    fi

    source "$PROD_CREDENTIALS_FILE"

    PROD_HOST="${PROD_DB_HOST:-$DB_HOST}"
    PROD_PORT="${PROD_DB_PORT:-${DB_PORT:-5432}}"
    PROD_USER="${PROD_DB_USERNAME:-$DB_USERNAME}"
    PROD_PASS="${PROD_DB_PASSWORD:-$DB_PASSWORD}"
    PROD_DB="${PROD_DB_DATABASE:-${DB_NAME:-$DB_DATABASE}}"

    local missing=0
    for v in PROD_HOST PROD_USER PROD_PASS PROD_DB; do
        if [ -z "${!v:-}" ]; then
            echo "  Missing: ${v#PROD_}"
            missing=1
        fi
    done

    if [ "$missing" -eq 1 ]; then
        echo "Error: Incomplete credentials in $PROD_CREDENTIALS_FILE"
        exit 1
    fi
}

# =========================================================
# MAIN
# =========================================================
echo "═════════════════════════════════════════════════════"
echo "  PG GET & CONVERT FROM PROD"
echo "  Production data → Schema 2 (structure preserved)"
echo "═════════════════════════════════════════════════════"
echo ""

check_prereqs
check_runtime_env

# Phase 1: Load credentials
echo "Phase 1/4 — Load production credentials"
echo "────────────────────────────────────────"
load_prod_credentials
echo "✓ Prod: $PROD_HOST/$PROD_DB"
echo ""

# Phase 2: Confirm
echo "Phase 2/4 — Confirm destructive sync"
echo "─────────────────────────────────────"
check_pg_connection
confirm_destructive \
  "Replace local DATA with production data" \
  "$PG_HOST:$PG_PORT/$PG_DB ← $PROD_HOST/$PROD_DB"
echo "✓ Confirmed"
echo ""

# Phase 3: Sync data + transform
echo "Phase 3/4 — Sync data from production + transform"
echo "─────────────────────────────────────────────────"
cd "$BASE_DIR"
php "$TRANSFORM_SCRIPT" all \
    "$PROD_HOST" "$PROD_PORT" "$PROD_DB" "$PROD_USER" "$PROD_PASS"
echo "✓ Phase 3/4 — Data synced and transformed"
echo ""

# Phase 4: Clear cache
echo "Phase 4/4 — Clear application cache"
echo "────────────────────────────────────"
php artisan optimize:clear
echo "✓ Phase 4/4 — Cache cleared"
echo ""

echo "═════════════════════════════════════════════════════"
echo "  ✓ SYNC COMPLETE"
echo "  Source: $PROD_HOST/$PROD_DB"
echo "  Local:  $PG_HOST:$PG_PORT/$PG_DB"
echo "═════════════════════════════════════════════════════"
echo ""
echo "Post-sync complete."
echo ""
echo "Schema 2 structure (loops, referrals) untouched."
echo "Only data was replaced."
