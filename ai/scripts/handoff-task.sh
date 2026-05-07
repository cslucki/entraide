#!/bin/bash

# =========================================================
# HANDOFF TASK SCRIPT
# =========================================================

set -e

# =========================================================
# CONFIG
# =========================================================

BASE_DIR="/home/cyril/claude-code/sites/test.laravel"

TODO_DIR="$BASE_DIR/TODO"

# =========================================================
# ARGUMENTS
# =========================================================

TASK_ID="$1"
NEW_OWNER="$2"

if [ -z "$TASK_ID" ]; then
  echo ""
  echo "Usage:"
  echo "./handoff-task.sh TASK-ID NEW_OWNER"
  echo ""
  echo "Example:"
  echo "./handoff-task.sh TASK-052 JULES"
  echo ""
  exit 1
fi

if [ -z "$NEW_OWNER" ]; then
  echo ""
  echo "Missing NEW_OWNER"
  echo ""
  exit 1
fi

# =========================================================
# FIND TASK FILE
# =========================================================

TASK_FILE=$(find "$TODO_DIR" -maxdepth 1 -name "${TASK_ID}-*.md" | head -1)

if [ ! -f "$TASK_FILE" ]; then
  echo ""
  echo "Task not found:"
  echo "$TASK_ID"
  echo ""
  exit 1
fi

# =========================================================
# TIMESTAMP
# =========================================================

NOW="$(date '+%Y-%m-%d %H:%M:%S') Europe/Paris"

# =========================================================
# EXPORT VARIABLES
# =========================================================

export TASK_FILE
export TASK_ID
export NEW_OWNER
export NOW

# =========================================================
# PYTHON UPDATE
# =========================================================

python3 <<'EOF'
import os
import re
from pathlib import Path

task_file = Path(os.environ["TASK_FILE"])

new_owner = os.environ["NEW_OWNER"]

now = os.environ["NOW"]

content = task_file.read_text()

# =====================================================
# FIND CURRENT OWNER
# =====================================================

match = re.search(r"owner:\s*(.+)", content)

current_owner = "UNKNOWN"

if match:
    current_owner = match.group(1).strip()

# =====================================================
# UPDATE OWNER
# =====================================================

content = re.sub(
    r"owner:\s*.+",
    f"owner: {new_owner}",
    content,
    count=1
)

# =====================================================
# UPDATE UPDATED_AT
# =====================================================

content = re.sub(
    r"updated_at:\s*.+",
    f"updated_at: {now}",
    content,
    count=1
)

# =====================================================
# ENABLE HANDOFF
# =====================================================

content = re.sub(
    r"handoff:\s*false|handoff:\s*true",
    "handoff: true",
    content,
    count=1
)

# =====================================================
# UPDATE LOCK
# =====================================================

content = re.sub(
r'''lock:
  status: LOCKED
  agent: .+
  since: .+''',
f'''lock:
  status: LOCKED
  agent: {new_owner}
  since: {now}''',
content,
count=1
)

# =====================================================
# UPDATE CONTRIBUTORS
# =====================================================

contributors_pattern = r"contributors:\s*\[\]"

contributors_block = f"""contributors:
  - {current_owner}
  - {new_owner}"""

if re.search(contributors_pattern, content):

    content = re.sub(
        contributors_pattern,
        contributors_block,
        content,
        count=1
    )

else:

    if f"- {new_owner}" not in content:

        content = re.sub(
            r"(contributors:\n(?:\s+- .+\n)*)",
            r"\1  - " + new_owner + "\n",
            content,
            count=1
        )

# =====================================================
# HANDOFF BLOCK
# =====================================================

handoff_block = f"""
## {now}

Previous Owner:
{current_owner}

New Owner:
{new_owner}

Status:
IN_PROGRESS

---
"""

content = content.replace(
"# Handoffs\n\nNo handoff yet.",
"# Handoffs\n" + handoff_block
)

if handoff_block not in content:
    content = content.replace(
        "# Handoffs",
        "# Handoffs\n" + handoff_block
    )

task_file.write_text(content)
EOF

# =========================================================
# DONE
# =========================================================

echo ""
echo "====================================="
echo "TASK HANDOFF COMPLETE"
echo "====================================="
echo ""
echo "Task:"
echo "$TASK_ID"
echo ""
echo "New Owner:"
echo "$NEW_OWNER"
echo ""
echo "Updated:"
echo "$TASK_FILE"
echo ""
