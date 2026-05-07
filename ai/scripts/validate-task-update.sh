#!/bin/bash

echo ""
echo "====================================="
echo "TASK VALIDATION"
echo "====================================="
echo ""

STAGED_FILES=$(git diff --cached --name-only)

TASK_FILES=$(echo "$STAGED_FILES" | grep "TODO/TASK-" || true)

if [ -z "$TASK_FILES" ]; then
    echo "ERROR:"
    echo "No TASK file staged."
    echo ""
    echo "You must update a TASK file before commit."
    echo ""
    exit 1
fi

echo "TASK file detected:"
echo "$TASK_FILES"
echo ""

echo "Validation OK."
echo ""
exit 0