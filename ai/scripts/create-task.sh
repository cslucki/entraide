#!/bin/bash

# =========================================================
# CREATE TASK SCRIPT
# =========================================================

set -e

# =========================================================
# CONFIG
# =========================================================

BASE_DIR="/home/cyril/claude-code/sites/test.laravel"

TODO_DIR="$BASE_DIR/TODO"
TEMPLATE_FILE="$BASE_DIR/ai/tasks/templates/TASK_TEMPLATE.md"

# =========================================================
# ARGUMENTS
# =========================================================

TITLE="$1"
OWNER="$2"

if [ -z "$TITLE" ]; then
  echo ""
  echo "Usage:"
  echo "  ./create-task.sh \"Task title\" OWNER [--subtask T###.##]"
  echo ""
  echo "Examples:"
  echo "  ./create-task.sh \"Fix navbar mobile bug\" GLM"
  echo "  ./create-task.sh \"ChatLoop interactions\" OPENCODE --subtask T074.1A"
  echo ""
  exit 1
fi

if [ -z "$OWNER" ]; then
  OWNER="GLM"
fi

# =========================================================
# OPTIONAL: --subtask flag (T074.x mode)
# =========================================================

SUBTASK=""
if [ "$3" = "--subtask" ] && [ -n "$4" ]; then
  SUBTASK="$4"
elif [[ "$3" == --subtask=* ]]; then
  SUBTASK="${3#*=}"
fi

# =========================================================
# TIMESTAMP
# =========================================================

NOW="$(date '+%Y-%m-%d %H:%M:%S') Europe/Paris"

# =========================================================
# TASK ID + FILE + BRANCH (standard or --subtask mode)
# =========================================================

if [ -n "$SUBTASK" ]; then
  # Validate subtask format: T###.##
  if ! [[ "$SUBTASK" =~ ^T([0-9]+)\.([A-Za-z0-9]+)$ ]]; then
    echo ""
    echo "ERROR: Invalid subtask format '$SUBTASK'. Expected T###.## (e.g. T074.1A)."
    echo ""
    exit 1
  fi

  NUM="${BASH_REMATCH[1]}"
  SUFFIX="${BASH_REMATCH[2]}"
  SUFFIX_LOWER=$(echo "$SUFFIX" | tr '[:upper:]' '[:lower:]')
  NUM_LOWER=$(echo "$NUM" | tr '[:upper:]' '[:lower:]')
  SUBTASK_SLUG="t${NUM_LOWER}-${SUFFIX_LOWER}"

  TASK_ID="TASK-${NUM}.${SUFFIX}"

  SLUG=$(echo "$TITLE" \
    | tr '[:upper:]' '[:lower:]' \
    | sed 's/[^a-z0-9]/-/g' \
    | sed 's/-\+/-/g' \
    | sed 's/^-//' \
    | sed 's/-$//')

  FILE_NAME="TASK-${NUM}-${SUBTASK_SLUG}-${SLUG}.md"
  TASK_FILE="$TODO_DIR/$FILE_NAME"
  BRANCH_NAME="${SUBTASK}-${SUBTASK_SLUG}-${SLUG}"

  # Refuse if file already exists
  if [ -f "$TASK_FILE" ]; then
    echo ""
    echo "ERROR: Task file already exists:"
    echo "  $TASK_FILE"
    echo ""
    exit 1
  fi

  # Refuse if branch already exists (local or remote)
  if git show-ref --verify --quiet "refs/heads/$BRANCH_NAME" 2>/dev/null; then
    echo ""
    echo "ERROR: Branch already exists locally: $BRANCH_NAME"
    echo ""
    exit 1
  fi

  if git show-ref --verify --quiet "refs/remotes/origin/$BRANCH_NAME" 2>/dev/null; then
    echo ""
    echo "ERROR: Branch already exists on origin: $BRANCH_NAME"
    echo ""
    exit 1
  fi

else
  # Standard mode: auto-increment TASK number
  LAST_TASK=$(find "$TODO_DIR" -maxdepth 1 -name "TASK-*.md" 2>/dev/null \
    | sed 's/.*TASK-\([0-9]*\).*/\1/' \
    | sort -n \
    | tail -1)

  if [ -z "$LAST_TASK" ]; then
    NEXT_TASK=50
  else
    NEXT_TASK=$((10#$LAST_TASK + 1))
  fi

  TASK_ID=$(printf "TASK-%03d" "$NEXT_TASK")

  SLUG=$(echo "$TITLE" \
    | tr '[:upper:]' '[:lower:]' \
    | sed 's/[^a-z0-9]/-/g' \
    | sed 's/-\+/-/g' \
    | sed 's/^-//' \
    | sed 's/-$//')

  FILE_NAME="${TASK_ID}-${SLUG}.md"
  TASK_FILE="$TODO_DIR/$FILE_NAME"
  BRANCH_NAME="${TASK_ID}-${SLUG}"
fi

# =========================================================
# COPY TEMPLATE
# =========================================================

cp "$TEMPLATE_FILE" "$TASK_FILE"

# =========================================================
# PYTHON UPDATE
# =========================================================

python3 <<EOF
from pathlib import Path

task_file = Path("$TASK_FILE")

content = task_file.read_text()

content = content.replace(
    "task_id: TASK-050",
    "task_id: $TASK_ID"
)

content = content.replace(
    "title: Example Task",
    "title: $TITLE"
)

content = content.replace(
    "status: TODO",
    "status: IN_PROGRESS"
)

content = content.replace(
    "owner: null",
    "owner: $OWNER"
)

content = content.replace(
    "branch: null",
    "branch: $BRANCH_NAME"
)

content = content.replace(
    "created_at: null",
    "created_at: $NOW"
)

content = content.replace(
    "updated_at: null",
    "updated_at: $NOW"
)

content = content.replace(
'''lock:
  status: UNLOCKED
  agent: null
  since: null''',
'''lock:
  status: LOCKED
  agent: $OWNER
  since: $NOW'''
)

log_block = f"""
## $NOW

Task created.

Owner:
$OWNER

Branch:
$BRANCH_NAME

Status:
IN_PROGRESS
"""

content = content.replace(
"# Handoffs",
log_block + "\n# Handoffs"
)

task_file.write_text(content)
EOF

# =========================================================
# CREATE GIT BRANCH
# =========================================================

git checkout -b "$BRANCH_NAME"

# =========================================================
# DONE
# =========================================================

echo ""
echo "====================================="
echo "TASK CREATED"
echo "====================================="
echo ""
echo "Task ID: $TASK_ID"
echo "Title: $TITLE"
echo "Owner: $OWNER"
echo ""
echo "Task file:"
echo "$TASK_FILE"
echo ""
echo "Git branch:"
echo "$BRANCH_NAME"
echo ""
echo "Status:"
echo "IN_PROGRESS"
echo ""
echo "Lock:"
echo "LOCKED by $OWNER"
echo ""