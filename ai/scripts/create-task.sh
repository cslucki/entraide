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
  echo "./create-task.sh \"Task title\" OWNER"
  echo ""
  echo "Example:"
  echo "./create-task.sh \"Fix navbar mobile bug\" GLM"
  echo ""
  exit 1
fi

if [ -z "$OWNER" ]; then
  OWNER="GLM"
fi

# =========================================================
# TIMESTAMP
# =========================================================

NOW="$(date '+%Y-%m-%d %H:%M:%S') Europe/Paris"

# =========================================================
# FIND NEXT TASK NUMBER
# =========================================================

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

# =========================================================
# GENERATE SLUG
# =========================================================

SLUG=$(echo "$TITLE" \
  | tr '[:upper:]' '[:lower:]' \
  | sed 's/[^a-z0-9]/-/g' \
  | sed 's/-\+/-/g' \
  | sed 's/^-//' \
  | sed 's/-$//')

FILE_NAME="${TASK_ID}-${SLUG}.md"

TASK_FILE="$TODO_DIR/$FILE_NAME"

BRANCH_NAME="${TASK_ID}-${SLUG}"

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