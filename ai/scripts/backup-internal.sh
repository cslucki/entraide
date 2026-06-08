#!/bin/bash

set -e

BACKUP_ROOT="/home/cyril/claude-code/local-backups/test.laravel-internal"
PROJECT_ROOT="/home/cyril/claude-code/sites/test.laravel"
EXCLUDE_FILE=".git/info/exclude"

echo "====================================="
echo "INTERNAL BACKUP"
echo "====================================="
echo ""
echo "Target: $BACKUP_ROOT"
echo ""

cd "$PROJECT_ROOT"

# Read items from exclude file
items=()
if [ -f "$EXCLUDE_FILE" ]; then
  while IFS= read -r line || [ -n "$line" ]; do
    line="${line#"${line%%[![:space:]]*}"}"
    [ -z "$line" ] && continue
    [[ "$line" == \#* ]] && continue
    items+=("$line")
  done < "$EXCLUDE_FILE"
fi

# Setup backup repo
mkdir -p "$BACKUP_ROOT"
git -C "$BACKUP_ROOT" init --quiet 2>/dev/null

count=0
for item in "${items[@]}"; do
  path="${item%/}"
  if [ -d "$path" ]; then
    echo "  dir:   $path/"
    rsync -a --delete "$path/" "$BACKUP_ROOT/$path/"
    count=$((count + 1))
  elif [ -f "$path" ]; then
    echo "  file:  $path"
    mkdir -p "$(dirname "$BACKUP_ROOT/$path")"
    cp "$path" "$BACKUP_ROOT/$path"
    count=$((count + 1))
  fi
done

echo ""
echo "$count item(s) processed."

# Commit
cd "$BACKUP_ROOT"
git add -A
if git diff --cached --quiet; then
  echo "Nothing new to commit."
else
  git commit -m "backup: $(date '+%Y-%m-%d %H:%M')"
  echo "Backup committed."
fi

echo ""
echo "====================================="
echo "BACKUP COMPLETE"
echo "====================================="
echo ""
echo "Repo: $BACKUP_ROOT"
echo "(no remote configured — local only)"
