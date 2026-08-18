#!/bin/bash

# =========================================================
# BUMP VERSION SCRIPT — WORKTREE-LOCAL COPY (TASK-1200)
# =========================================================
# Adapte depuis test.laravel/ai/scripts/bump-version.sh.
# Le defaut de BASE_DIR resout desormais par rapport a l'emplacement
# physique de ce fichier (SCRIPT_DIR/../..) au lieu de test.laravel code en
# dur. VERSION_BASE_DIR reste accepte en derogation explicite si besoin.
#
# Usage: ./bump-version.sh [VERSION]
#
# Updates the VERSION file with the next project version.
# Version format: MAJOR.PATCH, with a 3-digit patch number (example: 1.000).
#
# Sources for new version (in order):
# 1. Explicit argument: ./bump-version.sh 1.004
# 2. Automatic increment from current VERSION.
# =========================================================

set -e

BASE_DIR="${VERSION_BASE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
VERSION_FILE="$BASE_DIR/VERSION"

echo ""
echo "====================================="
echo "BUMP VERSION"
echo "====================================="
echo ""

CURRENT_BRANCH=$(git branch --show-current 2>/dev/null || echo "")

if [ "$CURRENT_BRANCH" = "main" ] || [ "$CURRENT_BRANCH" = "develop" ]; then
  echo "ERROR: Cannot bump version on $CURRENT_BRANCH."
  echo "  VERSION must only be updated on a task branch (TASK-xxx)."
  echo ""
  exit 1
fi

REQUESTED_VERSION=""

# =========================================================
# 1. READ REQUESTED VERSION
# =========================================================

if [ -n "$1" ]; then
  REQUESTED_VERSION="$1"
  echo "Requested version from argument: $REQUESTED_VERSION"
fi

# =========================================================
# 2. READ CURRENT VERSION
# =========================================================

if [ ! -f "$VERSION_FILE" ]; then
  CURRENT_VERSION="0.1000"
else
  CURRENT_VERSION=$(cat "$VERSION_FILE" | tr -d '[:space:]')
fi

echo "Current version: $CURRENT_VERSION"
echo ""

# =========================================================
# 3. COMPUTE NEW VERSION
# =========================================================

if [ -n "$REQUESTED_VERSION" ]; then
  if [[ ! $REQUESTED_VERSION =~ ^[0-9]+\.[0-9]{3}$ ]]; then
    echo "ERROR: Invalid version '$REQUESTED_VERSION'. Expected format: MAJOR.PATCH (example: 1.000)."
    exit 1
  fi
  NEW_VERSION="$REQUESTED_VERSION"
else
  if [[ $CURRENT_VERSION =~ ^0\. ]]; then
    NEW_VERSION="1.000"
  elif [[ $CURRENT_VERSION =~ ^([0-9]+)\.([0-9]{3})$ ]]; then
    MAJOR="${BASH_REMATCH[1]}"
    PATCH="${BASH_REMATCH[2]}"
    PATCH_NUMBER=$((10#$PATCH + 1))
    if [ "$PATCH_NUMBER" -gt 999 ]; then
      MAJOR=$((MAJOR + 1))
      PATCH_NUMBER=0
    fi
    NEW_VERSION=$(printf "%d.%03d" "$MAJOR" "$PATCH_NUMBER")
  else
    echo "ERROR: Current VERSION '$CURRENT_VERSION' does not match supported formats."
    echo "  Expected old alpha format '0.x' or new format 'MAJOR.PATCH' (example: 1.000)."
    exit 1
  fi
fi

echo "New version: $NEW_VERSION"
echo ""

# =========================================================
# 4. WRITE NEW VERSION
# =========================================================

echo "$NEW_VERSION" > "$VERSION_FILE"

echo "====================================="
echo "VERSION UPDATED"
echo "====================================="
echo ""
echo "Old version: $CURRENT_VERSION"
echo "New version: $NEW_VERSION"
echo "File updated: $VERSION_FILE"
echo ""
