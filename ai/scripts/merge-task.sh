#!/bin/bash

# =========================================================
# MERGE TASK SCRIPT
# =========================================================
# Usage: ./merge-task.sh [TASK_ID]
#
# Safely merges the current task branch into develop.
#
# Safety:
# - requires clean git status before merge
# - requires being on a task branch (not main/develop)
# - uses --no-ff for explicit merge commits
# - requires confirmation before pushing develop
# - verifies merge success
# - does NOT auto-resolve conflicts
# =========================================================

set -e

BASE_DIR="/home/cyril/claude-code/sites/test.laravel"
SCRIPTS_DIR="$BASE_DIR/ai/scripts"

echo ""
echo "====================================="
echo "MERGE TASK"
echo "====================================="
echo ""

CURRENT_BRANCH=$(git branch --show-current)

# =========================================================
# 1. CONFIRM NOT ON MAIN/DEVELOP
# =========================================================

if [ "$CURRENT_BRANCH" = "main" ]; then
  echo "ERROR: Cannot merge from main branch."
  echo ""
  exit 1
fi

if [ "$CURRENT_BRANCH" = "develop" ]; then
  echo "ERROR: Already on develop branch."
  echo ""
  exit 1
fi

echo "Source branch: $CURRENT_BRANCH"
echo "Target branch: develop"
echo ""

# =========================================================
# 2. RUN TASK CHECK (gate: requires DONE + UNLOCKED)
# =========================================================

echo "Running pre-merge task check..."
echo ""

if ! bash "$SCRIPTS_DIR/check-task.sh" "$@"; then
  echo ""
  echo "====================================="
  echo "TASK CHECK FAILED"
  echo "====================================="
  echo ""
  echo "Task must be DONE and UNLOCKED before merging."
  echo "Resolve issues and re-run merge-task.sh."
  echo ""
  exit 1
fi

echo ""
echo "Task check passed."
echo ""

# =========================================================
# 3. REQUIRE CLEAN STATUS (porcelain detects ALL: staged, unstaged, untracked)
# =========================================================

PORCELAIN=$(git status --porcelain)

if [ -n "$PORCELAIN" ]; then
  echo "ERROR: Uncommitted changes detected."
  echo ""
  git status --short
  echo ""
  echo "Commit or stash changes before merging."
  echo ""
  exit 1
fi

echo "Git status: CLEAN"
echo ""

# =========================================================
# 4. CONFIRM
# =========================================================

read -p "Merge $CURRENT_BRANCH into develop? (y/n): " CONFIRM

if [ "$CONFIRM" != "y" ]; then
  echo ""
  echo "Merge cancelled."
  echo ""
  exit 0
fi

# =========================================================
# 5. FETCH DEVELOP
# =========================================================

echo ""
echo "Fetching latest develop..."
echo ""

git fetch origin develop 2>&1 || echo "  Warning: could not fetch (remote may not be configured)."

# =========================================================
# 6. CHECKOUT DEVELOP
# =========================================================

echo ""
echo "Switching to develop..."
echo ""

git checkout develop

echo ""
echo "Pulling latest develop..."
echo ""

git pull origin develop 2>&1 || echo "  Warning: could not pull (remote may not be configured)."

# =========================================================
# 7. MERGE
# =========================================================

echo ""
echo "Merging $CURRENT_BRANCH into develop..."
echo ""
echo "====================================="
echo "MERGE COMMAND"
echo "====================================="
echo ""
echo "git merge --no-ff $CURRENT_BRANCH"
echo ""

if git merge --no-ff "$CURRENT_BRANCH"; then
  echo ""
  echo "Merge successful."
else
  echo ""
  echo "====================================="
  echo "MERGE CONFLICT"
  echo "====================================="
  echo ""
  echo "Resolve conflicts manually, then:"
  echo "  git add ."
  echo "  git commit"
  echo "  git push origin develop"
  echo ""
  exit 1
fi

# =========================================================
# 8. PUSH DEVELOP (with confirmation)
# =========================================================

echo ""
echo "Ready to push the updated develop branch."
echo ""

read -p "Push develop to origin? (y/n): " PUSH_CONFIRM

if [ "$PUSH_CONFIRM" = "y" ]; then
  echo ""
  echo "Pushing develop..."
  echo ""
  git push origin develop
  echo ""
  echo "Develop pushed."
else
  echo ""
  echo "Push skipped. Remember to push manually:"
  echo "  git push origin develop"
fi

# =========================================================
# 9. VERIFY CLEAN
# =========================================================

echo ""
echo "Verifying git status..."
echo ""

git status --short

echo ""
echo "====================================="
echo "MERGE COMPLETE"
echo "====================================="
echo ""
echo "Branch '$CURRENT_BRANCH' merged into develop."
echo ""
echo "Reminders:"
echo "  - Update TASK status to MERGED"
echo "  - Delete remote branch if desired:"
echo "    git push origin --delete $CURRENT_BRANCH"
echo "  - Delete local branch:"
echo "    git branch -d $CURRENT_BRANCH"
echo ""
