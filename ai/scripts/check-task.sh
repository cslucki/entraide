#!/bin/bash

# =========================================================
# CHECK TASK SCRIPT
# =========================================================
# Usage: ./check-task.sh [--dry-run|-n] [TASK_ID]
#
# Checks:
# - current git branch
# - TASK file detection (supports T074.1A, TASK-074-prefix, full path)
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
DRY_RUN=false

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
# 2. TASK FILE DETECTION (subtask-aware: T074.1A, TASK-074-prefix, full path)
# =========================================================

# Resolve a task reference to a TASK file path.
# Supports:
#   TODO/TASK-xxx.md        — full path
#   TASK-xxx.md             — basename
#   T074.1A                 — subtask shorthand
#   TASK-074-t074-1a        — file prefix
#   TASK-074                — legacy task ID
#   074                     — bare number
# Returns:
#   0 + stdout = single file path
#   1          = no match
#   2 + stdout = multiple matches (listed)
resolve_task_file() {
  local input="$1"
  local matches="" count=0

  # Case 1: Direct .md path (absolute/relative, with or without TODO/)
  if [[ "$input" == *".md" ]]; then
    if [ -f "$input" ]; then
      echo "$input"
      return 0
    fi
    local base; base=$(basename "$input")
    if [ -f "$TODO_DIR/$base" ]; then
      echo "$TODO_DIR/$base"
      return 0
    fi
    return 1
  fi

  # Case 2: T074.1A subtask shorthand → TASK-074-t074-1a-*.md
  if [[ "$input" =~ ^T([0-9]+)\.([A-Za-z0-9]+)$ ]]; then
    local num="${BASH_REMATCH[1]}"
    local suffix; suffix=$(echo "${BASH_REMATCH[2]}" | tr '[:upper:]' '[:lower:]')
    local num_lower; num_lower=$(echo "$num" | tr '[:upper:]' '[:lower:]')
    matches=$(find "$TODO_DIR" -maxdepth 1 -name "TASK-${num}-t${num_lower}-${suffix}*.md" 2>/dev/null | sort)
    if [ -n "$matches" ]; then
      count=$(echo "$matches" | wc -l)
      [ "$count" -eq 1 ] && { echo "$matches"; return 0; }
      [ "$count" -gt 1 ] && { echo "$matches"; return 2; }
    fi
    return 1
  fi

  # Case 3: TASK-XXX-prefix (TASK-074-t074-1a, TASK-073A, TASK-073, TASK-058-orga...)
  if [[ "$input" == TASK-* ]]; then
    matches=$(find "$TODO_DIR" -maxdepth 1 -name "${input}*.md" 2>/dev/null | sort)
    if [ -n "$matches" ]; then
      count=$(echo "$matches" | wc -l)
      [ "$count" -eq 1 ] && { echo "$matches"; return 0; }
      if [ "$count" -gt 1 ]; then
        local matches2; matches2=$(find "$TODO_DIR" -maxdepth 1 -name "${input}-*.md" 2>/dev/null | sort)
        if [ -n "$matches2" ]; then
          local count2; count2=$(echo "$matches2" | wc -l)
          [ "$count2" -eq 1 ] && { echo "$matches2"; return 0; }
          [ "$count2" -gt 1 ] && { echo "$matches2"; return 2; }
        fi
        echo "$matches"
        return 2
      fi
    fi
    return 1
  fi

  # Case 4: Legacy bare number → TASK-{num}
  if [[ "$input" =~ ^[0-9]+$ ]]; then
    matches=$(find "$TODO_DIR" -maxdepth 1 -name "TASK-${input}-*.md" 2>/dev/null | sort)
    if [ -n "$matches" ]; then
      count=$(echo "$matches" | wc -l)
      [ "$count" -eq 1 ] && { echo "$matches"; return 0; }
      [ "$count" -gt 1 ] && { echo "$matches"; return 2; }
    fi
    return 1
  fi

  return 1
}

# Extract task reference from branch name
extract_task_ref() {
  local branch="$1"
  # Try T074.1A-... subtask pattern first
  if [[ "$branch" =~ ^(T[0-9]+\.[A-Za-z0-9]+) ]]; then
    echo "${BASH_REMATCH[1]}"
    return 0
  fi
  # Try legacy TASK-XXX-... pattern
  if [[ "$branch" =~ ^(TASK-[0-9]+) ]]; then
    echo "${BASH_REMATCH[1]}"
    return 0
  fi
  return 1
}

# Parse arguments: TASK_ID (first non-flag), --dry-run/-n
TASK_ARG=""
for arg in "$@"; do
  if [ "$arg" = "--dry-run" ] || [ "$arg" = "-n" ]; then
    DRY_RUN=true
  elif [ -z "$TASK_ARG" ]; then
    TASK_ARG="$arg"
  fi
done
TASK_FILE=""

if [ -n "$TASK_ARG" ]; then
  RESOLVE_EXIT=0
  TASK_FILE=$(resolve_task_file "$TASK_ARG") || RESOLVE_EXIT=$?
  if [ "$RESOLVE_EXIT" -eq 1 ]; then
    echo "  ERROR: No task file found for argument '$TASK_ARG'."
    echo ""
    RESULT=$FAIL
  elif [ "$RESOLVE_EXIT" -eq 2 ]; then
    echo "  ERROR: Multiple task files match argument '$TASK_ARG':"
    echo "$TASK_FILE" | sed 's/^/    /'
    echo ""
    RESULT=$FAIL
  fi
else
  TASK_REF=""
  TASK_REF=$(extract_task_ref "$CURRENT_BRANCH") || true
  if [ -n "$TASK_REF" ]; then
    RESOLVE_EXIT=0
    TASK_FILE=$(resolve_task_file "$TASK_REF") || RESOLVE_EXIT=$?
    if [ "$RESOLVE_EXIT" -eq 1 ]; then
      echo "  ERROR: No task file found for branch '$CURRENT_BRANCH' (ref: '$TASK_REF')."
      echo ""
      RESULT=$FAIL
    elif [ "$RESOLVE_EXIT" -eq 2 ]; then
      echo "  ERROR: Multiple task files match branch '$CURRENT_BRANCH' (ref: '$TASK_REF'):"
      echo "$TASK_FILE" | sed 's/^/    /'
      echo ""
      RESULT=$FAIL
    fi
  else
    echo "  ERROR: Could not extract task reference from branch '$CURRENT_BRANCH'."
    echo ""
    RESULT=$FAIL
  fi
fi

if [ -f "$TASK_FILE" ]; then
  echo "Task file: $(basename "$TASK_FILE")"
fi

# =========================================================
# 3 + 4. TASK STATUS + LOCK (Python YAML parse)
# =========================================================

if [ -f "$TASK_FILE" ]; then

  export TASK_FILE

  PY_EXIT=0
  python3 << 'PYEOF' || PY_EXIT=$?
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

if [ "$DRY_RUN" = true ]; then
  echo "====================================="
  echo "DRY-RUN MODE — no failure, summary above."
  echo "====================================="
  echo ""
  exit $PASS
fi

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
