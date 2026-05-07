#!/bin/bash

# =========================================================
# SAFE COMMIT SCRIPT
# =========================================================

set -e

# =========================================================
# CURRENT BRANCH
# =========================================================

CURRENT_BRANCH=$(git branch --show-current)

# =========================================================
# PROTECT MAIN
# =========================================================

if [ "$CURRENT_BRANCH" = "main" ]; then

  echo ""
  echo "====================================="
  echo "ERROR"
  echo "====================================="
  echo ""
  echo "Direct commits to MAIN are forbidden."
  echo ""
  echo "Switch to:"
  echo "- develop"
  echo "- TASK branch"
  echo ""
  exit 1
fi

# =========================================================
# DISPLAY INFO
# =========================================================

echo ""
echo "====================================="
echo "SAFE COMMIT"
echo "====================================="
echo ""

echo "Current branch:"
echo "$CURRENT_BRANCH"

echo ""

echo "====================================="
echo "GIT STATUS"
echo "====================================="
echo ""

git status

echo ""

# =========================================================
# ASK COMMIT MESSAGE
# =========================================================

read -p "Commit message: " COMMIT_MESSAGE

if [ -z "$COMMIT_MESSAGE" ]; then
  echo ""
  echo "Commit cancelled."
  echo ""
  exit 1
fi

# =========================================================
# ADD + COMMIT
# =========================================================

git add .

git commit -m "$COMMIT_MESSAGE"

# =========================================================
# PUSH OPTION
# =========================================================

echo ""

read -p "Push branch to origin? (y/n): " PUSH_CONFIRM

if [ "$PUSH_CONFIRM" = "y" ]; then

  git push -u origin "$CURRENT_BRANCH"

  echo ""
  echo "Branch pushed."
  echo ""

else

  echo ""
  echo "Push skipped."
  echo ""

fi

# =========================================================
# DONE
# =========================================================

echo "====================================="
echo "COMMIT COMPLETE"
echo "====================================="
echo ""
