#!/bin/bash

# =========================================================
# CHECK TASK SCRIPT
# =========================================================
# Usage: ./check-task.sh [TASK_ID]
#
# Checks:
# - current git branch
# - TASK file detection
# - TASK status == DONE
# - lock status == UNLOCKED
# - uncommitted/untracked changes
#
# Exit codes:
# 0 - all checks pass
# 1 - any check fails
# =========================================================

set -e

BASE_DIR="/home/cyril/claude-code/sites/test.laravel"
TODO_DIR="$BASE_DIR/TODO"

PASS=0
FAIL=1

RESULT=0

echo ""
echo "====================================="
echo "TASK CHECK"
echo "====================================="
echo ""

# =========================================================
# 1. CURRENT BRANCH
# =========================================================

CURRENT_BRANCH=$(git branch --show-current)

echo "Branch: $CURRENT_BRANCH"

if [ "$CURRENT_BRANCH" = "main" ]; then
  echo "  ERROR: On main branch. Task branches only."
  echo ""
  RESULT=$FAIL
fi

if [ "$CURRENT_BRANCH" = "develop" ]; then
  echo "  ERROR: On develop branch. Task branches only."
  echo ""
  RESULT=$FAIL
fi

# =========================================================
# 2. TASK FILE DETECTION
# =========================================================

TASK_ARG="$1"

if [ -n "$TASK_ARG" ]; then
  TASK_FILE=$(find "$TODO_DIR" -maxdepth 1 -name "${TASK_ARG}-*.md" | head -1)
else
  TASK_ID=$(echo "$CURRENT_BRANCH" | grep -o 'TASK-[0-9]\{3\}')
  TASK_FILE=$(find "$TODO_DIR" -maxdepth 1 -name "${TASK_ID}-*.md" | head -1)
fi

if [ ! -f "$TASK_FILE" ]; then
  echo "  ERROR: TASK file not found."
  echo "  Looked for: ${TASK_ID}-*.md"
  echo ""
  RESULT=$FAIL
else
  echo "Task file: $(basename "$TASK_FILE")"
fi

# =========================================================
# 3 + 4. TASK STATUS + LOCK (Python YAML parse)
# =========================================================

if [ -f "$TASK_FILE" ]; then

  export TASK_FILE

  python3 << 'PYEOF'
import os, sys, re

task_file = os.environ.get('TASK_FILE')
if not task_file:
    print("  ERROR: TASK_FILE not set.")
    sys.exit(1)

with open(task_file) as f:
    content = f.read()

match = re.match(r'^---\n(.*?)\n(?:---|\.\.\.)', content, re.DOTALL)
if not match:
    print("  ERROR: Could not parse YAML frontmatter.")
    sys.exit(1)

yaml_text = match.group(1)

result = {}
prefix = None

for line in yaml_text.split('\n'):
    stripped = line.strip()
    if not stripped or stripped.startswith('#'):
        continue
    indent = len(line) - len(line.lstrip())
    if ':' not in stripped:
        continue
    key = stripped.split(':')[0].strip()
    value = ':'.join(stripped.split(':')[1:]).strip()
    if indent == 0:
        result[key] = value
        prefix = key
    elif prefix:
        result[f"{prefix}.{key}"] = value

status = result.get('status', '')
lock_status = result.get('lock.status', '')

print(f"Status: {status}")

if status != 'DONE':
    print(f"  ERROR: Status must be DONE (found: {status}).")

print(f"Lock: {lock_status}")

if lock_status != 'UNLOCKED':
    print(f"  ERROR: Task must be UNLOCKED (found: {lock_status}).")

if status != 'DONE' or lock_status != 'UNLOCKED':
    sys.exit(1)
PYEOF

  PY_EXIT=$?

  if [ "$PY_EXIT" -ne 0 ]; then
    echo ""
    RESULT=$FAIL
  fi
fi

# =========================================================
# 5. UNCOMMITTED CHANGES (porcelain detects ALL: staged, unstaged, untracked)
# =========================================================

PORCELAIN=$(git status --porcelain)

if [ -n "$PORCELAIN" ]; then
  echo "Uncommitted: YES"
  echo ""
  echo "$PORCELAIN" | sed 's/^/  /'
  echo ""
else
  echo "Uncommitted: NO"
  echo ""
fi

# =========================================================
# RESULT
# =========================================================

if [ "$RESULT" -eq $FAIL ]; then
  echo "====================================="
  echo "CHECK FAILED"
  echo "====================================="
  echo ""
  exit $FAIL
fi

echo "====================================="
echo "CHECK PASSED"
echo "====================================="
echo ""

exit $PASS
