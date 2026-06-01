#!/bin/bash

# =========================================================
# BUMP VERSION SCRIPT
# =========================================================
# Usage: ./bump-version.sh [TASK_ID]
#
# Updates the VERSION file with a new version number based on task ID.
# Version format: v0.{TASK_ID}-alpha
#
# Sources for task ID (in order):
# 1. Explicit argument: ./bump-version.sh 138
# 2. Current branch name: TASK-138-feature-name
# =========================================================

set -e

BASE_DIR="/home/cyril/claude-code/sites/test.laravel"
VERSION_FILE="$BASE_DIR/VERSION"

echo ""
echo "====================================="
echo "BUMP VERSION"
echo "====================================="
echo ""

TASK_ID=""

# =========================================================
# 1. EXTRACT TASK ID
# =========================================================

if [ -n "$1" ]; then
  RAW="$1"
  if [[ $RAW =~ ^TASK-([0-9]+) ]]; then
    TASK_ID="${BASH_REMATCH[1]}"
  else
    TASK_ID="$RAW"
  fi
  echo "Task ID from argument: $TASK_ID"
else
  CURRENT_BRANCH=$(git branch --show-current 2>/dev/null || echo "")
  if [[ $CURRENT_BRANCH =~ ^TASK-([0-9]+) ]]; then
    TASK_ID="${BASH_REMATCH[1]}"
    echo "Task ID from branch: $TASK_ID"
  fi
fi

if [ -z "$TASK_ID" ]; then
  echo ""
  echo "====================================="
  echo "ERROR"
  echo "====================================="
  echo ""
  echo "Cannot determine task ID."
  echo ""
  echo "Usage:"
  echo "  ./bump-version.sh TASK_ID"
  echo "  ./bump-version.sh"
  echo ""
  echo "Without argument, script attempts to extract task ID from branch name:"
  echo "  TASK-138-feature-name -> 138"
  echo ""
  exit 1
fi

# =========================================================
# 2. READ CURRENT VERSION
# =========================================================

if [ ! -f "$VERSION_FILE" ]; then
  CURRENT_VERSION="v0.0-alpha"
else
  CURRENT_VERSION=$(cat "$VERSION_FILE" | tr -d '[:space:]')
fi

echo "Current version: $CURRENT_VERSION"
echo ""

# =========================================================
# 3. COMPUTE NEW VERSION
# =========================================================

NEW_VERSION="v0.$TASK_ID-alpha"

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
echo ""
echo "File updated: $VERSION_FILE"
echo ""