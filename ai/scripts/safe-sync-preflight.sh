#!/bin/bash
# =========================================================
# SAFE SYNC PREFLIGHT / DRY-RUN GUARD
# =========================================================
#
# This script is read-only by design.
# It does not dump, import, sync storage, run migrations, clear cache,
# call Laravel Cloud, or write runtime files.
#
# Usage:
#   ./ai/scripts/safe-sync-preflight.sh
#   ./ai/scripts/safe-sync-preflight.sh --dry-run
# =========================================================

set -u

BASE_DIR="/home/cyril/claude-code/sites/test.laravel"
SCRIPTS_DIR="$BASE_DIR/ai/scripts"
DUMPS_DIR="$BASE_DIR/storage/app/dumps"
STORAGE_PUBLIC_DIR="$BASE_DIR/storage/app/public"
PUBLIC_STORAGE_LINK="$BASE_DIR/public/storage"
ENV_EXAMPLE="$BASE_DIR/.env.example"
ENV_PGSQL="$BASE_DIR/.env.pgsql"
PROD_CREDENTIALS_FILE="/home/cyril/.config/bouclepro/prod-db.env"

OK_COUNT=0
WARN_COUNT=0
FAIL_COUNT=0

usage() {
    cat <<'EOF'
Safe sync preflight / dry-run guard.

Allowed:
  ./ai/scripts/safe-sync-preflight.sh
  ./ai/scripts/safe-sync-preflight.sh --dry-run

This script never runs dump, import, sync, migration, cache clear,
Laravel Cloud, PROD, or ALPHA operations.
EOF
}

ok() {
    OK_COUNT=$((OK_COUNT + 1))
    printf 'OK   %s\n' "$1"
}

warn() {
    WARN_COUNT=$((WARN_COUNT + 1))
    printf 'WARN %s\n' "$1"
}

fail() {
    FAIL_COUNT=$((FAIL_COUNT + 1))
    printf 'FAIL %s\n' "$1"
}

section() {
    printf '\n== %s ==\n' "$1"
}

has_env_key() {
    local file="$1"
    local key="$2"

    [ -f "$file" ] && grep -q "^${key}=" "$file" 2>/dev/null
}

env_value() {
    local file="$1"
    local key="$2"

    grep "^${key}=" "$file" 2>/dev/null | head -1 | cut -d '=' -f2-
}

check_no_action_args() {
    case "${1:---dry-run}" in
        ""|--dry-run)
            ok "dry-run mode only; no action argument provided"
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            fail "action '$1' rejected; this preflight never executes sync operations"
            ;;
    esac
}

check_git_state() {
    local branch
    local porcelain

    branch=$(git -C "$BASE_DIR" branch --show-current 2>/dev/null || true)
    porcelain=$(git -C "$BASE_DIR" status --porcelain 2>/dev/null || true)

    if [ -n "$branch" ]; then
        ok "current branch: $branch"
    else
        fail "could not determine current branch"
    fi

    case "$branch" in
        main|develop|ALPHA*)
            fail "sync preflight should run from a dedicated task branch, not '$branch'"
            ;;
        TASK-*)
            ok "branch looks like a dedicated task branch"
            ;;
        *)
            warn "branch is not main/develop/ALPHA, but does not match TASK-*"
            ;;
    esac

    if [ -z "$porcelain" ]; then
        ok "git working tree is clean"
    else
        warn "git working tree has local changes; review before any future sync"
        git -C "$BASE_DIR" status --short
    fi
}

check_local_environment() {
    if [ -f "$ENV_EXAMPLE" ]; then
        ok ".env.example exists"
    else
        fail ".env.example is missing"
    fi

    if [ -f "$ENV_PGSQL" ]; then
        ok ".env.pgsql exists (values not printed)"
    else
        warn ".env.pgsql is missing; local PostgreSQL checks cannot run"
    fi

    if [ -f "$BASE_DIR/.env" ]; then
        warn ".env exists but was not read or printed"
    else
        warn ".env is absent; active runtime was not inspected"
    fi
}

check_env_keys() {
    local missing=0
    local key
    local keys=("DB_CONNECTION" "DB_HOST" "DB_PORT" "DB_DATABASE" "DB_USERNAME" "DB_PASSWORD")

    if [ ! -f "$ENV_PGSQL" ]; then
        warn "skipping .env.pgsql key check because file is missing"
        return
    fi

    for key in "${keys[@]}"; do
        if has_env_key "$ENV_PGSQL" "$key"; then
            ok ".env.pgsql contains $key (value hidden)"
        else
            warn ".env.pgsql missing $key"
            missing=1
        fi
    done

    if [ "$missing" -eq 0 ]; then
        ok "required local PostgreSQL variable names are present"
    fi
}

check_scripts() {
    local script
    local scripts=(
        "pg-dump.sh"
        "media-pull.sh"
        "switch-db.sh"
    )

    for script in "${scripts[@]}"; do
        if [ -x "$SCRIPTS_DIR/$script" ]; then
            ok "required script exists and is executable: ai/scripts/$script"
        elif [ -f "$SCRIPTS_DIR/$script" ]; then
            warn "required script exists but is not executable: ai/scripts/$script"
        else
            fail "required script missing: ai/scripts/$script"
        fi
    done
}

check_paths() {
    if git -C "$BASE_DIR" check-ignore -q "storage/app/dumps/"; then
        ok "storage/app/dumps/ is gitignored"
    else
        fail "storage/app/dumps/ is not gitignored"
    fi

    if [ -d "$DUMPS_DIR" ]; then
        ok "dump directory exists: storage/app/dumps"
    else
        warn "dump directory does not exist; dry-run did not create it"
    fi

    if [ -d "$STORAGE_PUBLIC_DIR" ]; then
        ok "storage public directory exists: storage/app/public"
    else
        warn "storage public directory missing: storage/app/public"
    fi

    if [ -L "$PUBLIC_STORAGE_LINK" ]; then
        ok "public/storage symlink exists"
    elif [ -e "$PUBLIC_STORAGE_LINK" ]; then
        warn "public/storage exists but is not a symlink"
    else
        warn "public/storage symlink missing; dry-run did not create it"
    fi
}

check_local_db() {
    local host port db user password connection

    for cmd in psql pg_dump pg_restore; do
        if command -v "$cmd" >/dev/null 2>&1; then
            ok "PostgreSQL client available: $cmd"
        else
            warn "PostgreSQL client missing: $cmd"
        fi
    done

    if [ ! -f "$ENV_PGSQL" ]; then
        warn "skipping local DB connection check because .env.pgsql is missing"
        return
    fi

    if ! command -v psql >/dev/null 2>&1; then
        warn "skipping local DB connection check because psql is missing"
        return
    fi

    connection=$(env_value "$ENV_PGSQL" "DB_CONNECTION")
    host=$(env_value "$ENV_PGSQL" "DB_HOST")
    port=$(env_value "$ENV_PGSQL" "DB_PORT")
    db=$(env_value "$ENV_PGSQL" "DB_DATABASE")
    user=$(env_value "$ENV_PGSQL" "DB_USERNAME")
    password=$(env_value "$ENV_PGSQL" "DB_PASSWORD")

    if [ "$connection" != "pgsql" ]; then
        warn ".env.pgsql DB_CONNECTION is not pgsql (value hidden)"
        return
    fi

    if [ -z "$host" ] || [ -z "$db" ] || [ -z "$user" ] || [ -z "$password" ]; then
        warn "local DB connection check skipped; required value missing (values hidden)"
        return
    fi

    if PGPASSWORD="$password" psql -h "$host" -p "${port:-5432}" -U "$user" -d "$db" -c "SELECT 1" >/dev/null 2>&1; then
        ok "local PostgreSQL is reachable (connection details hidden)"
    else
        warn "local PostgreSQL is not reachable or credentials are invalid (details hidden)"
    fi
}

check_prod_credentials_guard() {
    if [ -f "$PROD_CREDENTIALS_FILE" ]; then
        ok "production credential file exists (values not read or printed)"
        local perms
        perms=$(stat -c "%a" "$PROD_CREDENTIALS_FILE" 2>/dev/null || true)
        if [ "$perms" = "600" ]; then
            ok "production credential file permissions are 600"
        else
            warn "production credential file permissions are '$perms' (expected 600)"
        fi
    else
        warn "production credential file not present; production dump cannot be authorized yet"
    fi
}

check_dangerous_commands() {
    local file="$SCRIPTS_DIR/pg-dump.sh"
    local patterns=(
        "prod-dump"
        "prod-mirror"
        "reset)"
        "import)"
        "pg_restore"
        "--clean"
        "migrate --force"
        "optimize:clear"
    )
    local found=0
    local pattern

    if [ ! -f "$file" ]; then
        fail "cannot inspect dangerous commands because ai/scripts/pg-dump.sh is missing"
        return
    fi

    for pattern in "${patterns[@]}"; do
        if grep -q -- "$pattern" "$file"; then
            warn "dangerous/sensitive path present in pg-dump.sh: $pattern"
            found=1
        fi
    done

    if grep -q "confirm_destructive" "$file"; then
        ok "pg-dump.sh contains an interactive destructive confirmation guard"
    else
        fail "pg-dump.sh lacks confirm_destructive guard"
    fi

    if [ "$found" -eq 1 ]; then
        ok "dangerous commands were detected by preflight and not executed"
    else
        warn "no known dangerous command patterns detected; review script drift"
    fi
}

print_summary() {
    section "Summary"
    printf 'OK: %s\n' "$OK_COUNT"
    printf 'WARN: %s\n' "$WARN_COUNT"
    printf 'FAIL: %s\n' "$FAIL_COUNT"

    if [ "$FAIL_COUNT" -gt 0 ]; then
        printf '\nRESULT: FAIL\n'
        exit 1
    fi

    if [ "$WARN_COUNT" -gt 0 ]; then
        printf '\nRESULT: WARN\n'
        exit 0
    fi

    printf '\nRESULT: OK\n'
}

main() {
    cd "$BASE_DIR" || {
        printf 'FAIL cannot cd to %s\n' "$BASE_DIR"
        exit 1
    }

    section "Safe Sync Preflight"
    check_no_action_args "${1:---dry-run}"

    section "Git"
    check_git_state

    section "Local Environment"
    check_local_environment
    check_env_keys

    section "Scripts"
    check_scripts

    section "Paths"
    check_paths

    section "Local Database"
    check_local_db

    section "Secrets"
    check_prod_credentials_guard

    section "Dangerous Commands"
    check_dangerous_commands

    section "Destructive Action Guard"
    ok "no dump/import/sync/migration/cache/Laravel Cloud command was executed"
    ok "unknown action arguments are rejected by default"

    print_summary
}

main "$@"
