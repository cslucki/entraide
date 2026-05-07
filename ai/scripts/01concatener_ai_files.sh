#!/bin/bash

# =========================================================
# AI CONTEXT DUMP GENERATOR
# =========================================================

# Base paths
BASE_DIR="/home/cyril/claude-code/sites/test.laravel"
AI_DIR="$BASE_DIR/ai"

# Output
OUTPUT_FILE="$BASE_DIR/01_ai_dump.txt"

# Timestamp
NOW=$(date '+%Y-%m-%d %H:%M:%S')

# =========================================================
# START
# =========================================================

echo "Generating AI dump..."

# On recrée le fichier proprement
rm -f "$OUTPUT_FILE"

{
  echo "=================================================="
  echo "AI PROJECT DUMP"
  echo "=================================================="
  echo ""
  echo "Generated: $NOW"
  echo ""
  echo "BASE_DIR: $BASE_DIR"
  echo "AI_DIR: $AI_DIR"
  echo ""

  # =========================================================
  # DIRECTORY TREE
  # =========================================================

  echo "=================================================="
  echo "DIRECTORY TREE"
  echo "=================================================="
  echo ""

  tree "$AI_DIR"

  echo ""
  echo ""

  # =========================================================
  # FILE CONTENTS
  # =========================================================

  echo "=================================================="
  echo "FILE CONTENTS"
  echo "=================================================="

  find "$AI_DIR" -type f \
    ! -path "*/scripts/*" \
    ! -name "*.png" \
    ! -name "*.jpg" \
    ! -name "*.jpeg" \
    ! -name "*.gif" \
    ! -name "*.webp" \
    ! -name "*.pdf" \
    ! -name "*.sqlite" \
    ! -name "*.db" \
    | sort | while read file; do

      echo ""
      echo "=============================="
      echo "FILE: $file"
      echo "=============================="
      echo ""

      batcat --style=plain "$file"

      echo ""
      echo ""

  done

} > "$OUTPUT_FILE"

# =========================================================
# COPY TO SCRIPTS
# =========================================================

mkdir -p "$AI_DIR/scripts"

cp "$OUTPUT_FILE" "$AI_DIR/scripts/"

# =========================================================
# DONE
# =========================================================

echo ""
echo "Dump generated:"
echo "$OUTPUT_FILE"

echo ""
echo "Copied to:"
echo "$AI_DIR/scripts/01_ai_dump.txt"