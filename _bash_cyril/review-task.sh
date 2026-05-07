#!/bin/bash

# =========================================================
# REVIEW TASK
# Personal operator helper
# =========================================================

set -e

TASK_ID="$1"

if [ -z "$TASK_ID" ]; then

  echo ""
  echo "Usage:"
  echo "./review-task.sh TASK-ID"
  echo ""
  echo "Example:"
  echo "./review-task.sh TASK-053"
  echo ""
  exit 1

fi

# =========================================================
# FETCH REMOTES
# =========================================================

echo ""
echo "====================================="
echo "FETCH REMOTES"
echo "====================================="
echo ""

git fetch --all

# =========================================================
# FIND REMOTE BRANCH
# =========================================================

REMOTE_BRANCH=$(git branch -r \
  | grep "$TASK_ID" \
  | grep -v HEAD \
  | head -1 \
  | sed 's/origin\///' \
  | xargs)

if [ -z "$REMOTE_BRANCH" ]; then

  echo ""
  echo "Branch not found for:"
  echo "$TASK_ID"
  echo ""
  exit 1

fi

# =========================================================
# CHECKOUT BRANCH
# =========================================================

echo ""
echo "====================================="
echo "CHECKOUT"
echo "====================================="
echo ""

echo "Branch:"
echo "$REMOTE_BRANCH"
echo ""

git checkout "$REMOTE_BRANCH" 2>/dev/null \
|| git checkout -b "$REMOTE_BRANCH" "origin/$REMOTE_BRANCH"

# =========================================================
# STATUS
# =========================================================

echo ""
echo "====================================="
echo "STATUS"
echo "====================================="
echo ""

git status

# =========================================================
# DIFF
# =========================================================

echo ""
echo "====================================="
echo "DIFF VS DEVELOP"
echo "====================================="
echo ""

git diff develop --stat

echo ""

# =========================================================
# TASK FILE
# =========================================================

echo "====================================="
echo "TASK FILE"
echo "====================================="
echo ""

find TODO -name "${TASK_ID}-*.md"

echo ""

# =========================================================
# READY
# =========================================================

echo "====================================="
echo "REVIEW READY"
echo "====================================="
echo ""

echo "Suggested next steps:"
echo ""
echo "1. Inspect code"
echo "2. Run tests"
echo "3. Validate screenshots"
echo "4. Run Playwright"
echo "5. Merge into develop if valid"
echo ""
