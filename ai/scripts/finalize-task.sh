#!/bin/bash

# =========================================================
# FINALIZE TASK SCRIPT
# =========================================================
# Usage: ./finalize-task.sh [TASK_ID]
#
# Finalization workflow:
# 1. run check-task.sh
# 2. optionally commit TASK updates (explicit paths only)
# 3. push branch
# 4. optionally inspect GitHub Actions
# 5. report summary
#
# Does NOT merge. Use merge-task.sh for merging.
# =========================================================

set -e

BASE_DIR="/home/cyril/claude-code/sites/test.laravel"
SCRIPTS_DIR="$BASE_DIR/ai/scripts"

echo ""
echo "====================================="
echo "FINALIZE TASK"
echo "====================================="
echo ""

CURRENT_BRANCH=$(git branch --show-current)

# =========================================================
# 1. RUN CHECK
# =========================================================

echo "Step 1: Running task check..."
echo ""

if ! bash "$SCRIPTS_DIR/check-task.sh" "$@"; then
  echo ""
  echo "====================================="
  echo "TASK CHECK FAILED"
  echo "====================================="
  echo ""
  echo "Fix the reported issues before finalizing."
  echo ""
  exit 1
fi

# =========================================================
# 2. COMMIT TASK UPDATES (explicit paths only)
# =========================================================

echo ""
echo "Step 2: Task updates"
echo ""

PORCELAIN=$(git status --porcelain)

if [ -z "$PORCELAIN" ]; then
  echo "  No uncommitted changes. Skipping commit."
else
  echo "  Uncommitted changes detected."
  echo ""
  git status --short
  echo ""

  read -p "  Commit TASK and script changes? (y/n): " COMMIT_CONFIRM

  if [ "$COMMIT_CONFIRM" = "y" ]; then
    read -p "  Commit message: " COMMIT_MESSAGE

    if [ -n "$COMMIT_MESSAGE" ]; then
      git add TODO/
      git add ai/scripts/
      git commit -m "$COMMIT_MESSAGE"
      echo "  Commit created."
    else
      echo "  Empty message. Commit skipped."
    fi
  else
    echo "  Commit skipped."
  fi
fi

# =========================================================
# 3. PUSH BRANCH
# =========================================================

echo ""
echo "Step 3: Push branch"
echo ""

REMOTE_EXISTS=$(git remote -v | head -1 || true)

if [ -z "$REMOTE_EXISTS" ]; then
  echo "  No remote configured. Push skipped."
else
  read -p "  Push branch to origin? (y/n): " PUSH_CONFIRM

  if [ "$PUSH_CONFIRM" = "y" ]; then
    echo ""
    git push -u origin "$CURRENT_BRANCH"
    echo ""
    echo "  Branch pushed."
  else
    echo "  Push skipped."
  fi
fi

# =========================================================
# 4. GITHUB ACTIONS CHECK (with failure warning)
# =========================================================

echo ""
echo "Step 4: GitHub Actions check"
echo ""

if command -v gh &> /dev/null; then
  read -p "  Check GitHub Actions status? (y/n): " GH_CONFIRM

  if [ "$GH_CONFIRM" = "y" ]; then
    echo ""

    RUNS_JSON=$(gh run list --branch "$CURRENT_BRANCH" --limit 5 --json conclusion,displayTitle,status,createdAt,headBranch,databaseId 2>/dev/null || echo "[]")

    echo "$RUNS_JSON" | python3 -c "
import json,sys
runs=json.load(sys.stdin)
if not runs:
    print('  No workflow runs found for this branch.')
    sys.exit(0)

print('  Latest workflow runs:')
print('')

warning = False
for r in runs:
    status=r.get('status','?')
    conclusion=r.get('conclusion','?')
    title=r.get('displayTitle','?')
    if conclusion == 'success':
        icon='PASS'
    elif conclusion in ('failure', 'cancelled'):
        icon='FAIL'
        warning = True
    else:
        icon='PEND'
    print(f'  [{icon}] {title}')
    print(f'         status={status}, conclusion={conclusion}')

if warning:
    print('')
    print('  ⚠ WARNING: One or more workflows failed or were cancelled.')
    print('  Review before merging: gh run list --branch $CURRENT_BRANCH')
print('')
" || echo "  Could not parse workflow status."

    echo "  Verify manually:"
    echo "  gh run list --branch $CURRENT_BRANCH"
    echo ""
  else
    echo "  CI check skipped."
  fi
else
  echo "  gh CLI not found. CI check skipped."
fi

# =========================================================
# 5. SUMMARY
# =========================================================

echo ""
echo "====================================="
echo "FINALIZATION SUMMARY"
echo "====================================="
echo ""
echo "Branch: $CURRENT_BRANCH"
echo ""

echo "Remaining steps (manual or merge-task.sh):"
echo "  1. git checkout develop"
echo "  2. git merge --no-ff $CURRENT_BRANCH"
echo "  3. git push origin develop"
echo ""

echo "====================================="
echo "FINALIZATION COMPLETE"
echo "====================================="
echo ""
