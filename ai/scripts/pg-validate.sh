#!/bin/bash
# =========================================================
# PG-VALIDATE — PostgreSQL Local Validation
# =========================================================
# Single reproducible command to validate PostgreSQL runtime.
#
# Usage:
#   ./ai/scripts/pg-validate.sh
#
# What it does:
#   1. Checks prerequisites (psql, php, artisan)
#   2. Ensures PostgreSQL is reachable
#   3. Creates bouclepro_test database if missing (test isolation)
#   4. Switches to PostgreSQL mode (via switch-db.sh)
#   5. Runs migrate:fresh --seed
#   6. Runs full PHPUnit suite on PostgreSQL (phpunit.pgsql.xml)
#   7. Reports results and exit code
# =========================================================

set -euo pipefail

BASE_DIR="/home/cyril/claude-code/sites/test.laravel"
STEP=0
PASS=0
FAIL=0

ok()   { PASS=$((PASS + 1)); echo "  ✅ $1"; }
warn() { echo "  ⚠️  $1"; }
fail() { FAIL=$((FAIL + 1)); echo "  ❌ $1"; }

step() {
    STEP=$((STEP + 1))
    echo ""
    echo "=============================================="
    echo " Step $STEP: $1"
    echo "=============================================="
}

summary() {
    echo ""
    echo "=============================================="
    echo " RESULTS"
    echo "=============================================="
    echo "  Passed: $PASS"
    echo "  Failed: $FAIL"
    echo "=============================================="

    if [ "$FAIL" -gt 0 ]; then
        echo ""
        echo "  ❌ VALIDATION FAILED — $FAIL step(s) failed."
        exit 1
    else
        echo ""
        echo "  ✅ POSTGRESQL VALIDATION PASSED."
        exit 0
    fi
}

cd "$BASE_DIR"

# ---- Step 1: Prerequisites ----
step "Checking prerequisites"

if command -v psql &>/dev/null; then
    ok "psql available ($(psql --version 2>&1 | head -1))"
else
    fail "psql not found"
fi

if command -v php &>/dev/null; then
    ok "php available ($(php -v 2>&1 | head -1))"
else
    fail "php not found"
fi

if [ -f "artisan" ]; then
    ok "artisan found"
else
    fail "artisan not found"
fi

if [ -f "phpunit.pgsql.xml" ]; then
    ok "phpunit.pgsql.xml found"
else
    fail "phpunit.pgsql.xml not found"
fi

if [ -f "ai/scripts/switch-db.sh" ]; then
    ok "switch-db.sh found"
else
    fail "switch-db.sh not found"
fi

# ---- Step 2: PostgreSQL reachable ----
step "Checking PostgreSQL connectivity"

PG_HOST=${PG_HOST:-127.0.0.1}
PG_PORT=${PG_PORT:-5432}
PG_USER=${PG_USER:-bouclepro}
PG_PASSWORD=${PG_PASSWORD:-bouclepro_local_2026}

export PGPASSWORD="$PG_PASSWORD"

if psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d postgres -c "SELECT 1" &>/dev/null; then
    ok "PostgreSQL reachable at $PG_HOST:$PG_PORT"
else
    fail "PostgreSQL NOT reachable at $PG_HOST:$PG_PORT — is the service running?"
    summary
fi

# ---- Step 3: bouclepro_test database ----
step "Ensuring bouclepro_test database exists"

if psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d postgres -tAc \
    "SELECT 1 FROM pg_database WHERE datname='bouclepro_test'" | grep -q 1; then
    ok "bouclepro_test database already exists"
else
    psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d postgres \
        -c "CREATE DATABASE bouclepro_test OWNER bouclepro;" &>/dev/null && \
        ok "bouclepro_test database created" || \
        fail "Failed to create bouclepro_test database"
fi

# ---- Step 4: Switch to PostgreSQL ----
step "Switching to PostgreSQL mode"

if grep -q "^DB_CONNECTION=pgsql" .env 2>/dev/null; then
    ok "Already on PostgreSQL mode"
else
    bash ai/scripts/switch-db.sh pgsql && \
        ok "Switched to PostgreSQL mode" || \
        fail "Failed to switch to PostgreSQL mode"
fi

# ---- Step 5: Migrate and seed ----
step "Running migrate:fresh --seed"

if php artisan migrate:fresh --seed --force 2>&1; then
    ok "migrate:fresh --seed completed"
else
    fail "migrate:fresh --seed failed"
    summary
fi

# ---- Step 6: Run PostgreSQL PHPUnit suite ----
step "Running PHPUnit on PostgreSQL (phpunit.pgsql.xml)"

TEST_OUTPUT=$(php vendor/bin/phpunit --configuration phpunit.pgsql.xml 2>&1) || true

# Extract test counts
TESTS_PASSED=$(echo "$TEST_OUTPUT" | grep -oP 'OK\s+\(\d+\s+test' | grep -oP '\d+')
TESTS_FAILED=$(echo "$TEST_OUTPUT" | grep -oP 'FAILURES!' || echo "")

if echo "$TEST_OUTPUT" | grep -q "FAILURES"; then
    FAILURES_COUNT=$(echo "$TEST_OUTPUT" | grep -oP 'Tests:\s+\d+' | tail -1 || echo "?")
    echo "$TEST_OUTPUT"
    fail "PHPUnit FAILURES — $FAILURES_COUNT tests failed"
else
    echo "$TEST_OUTPUT"
    ok "PHPUnit passed ($TESTS_PASSED tests)"
fi

# ---- Results ----
summary
