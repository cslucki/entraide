#!/bin/bash
# =========================================================
# MEDIA PULL — download production media to local storage
# =========================================================
# Usage:
#   ./ai/scripts/media-pull.sh <url>
#
# Examples:
#   ./ai/scripts/media-pull.sh "https://bouclepro.com/avatars/foo.png"
#   ./ai/scripts/media-pull.sh "https://bouclepro.com/services/bar.jpg"
#   ./ai/scripts/media-pull.sh "https://bouclepro.com/blog/header.webp"
# =========================================================

set -e

BASE_DIR="/home/cyril/claude-code/sites/test.laravel"
STORAGE_DIR="$BASE_DIR/storage/app/public"

check_prereqs() {
    if ! command -v curl &>/dev/null; then
        echo "Error: 'curl' not found. Install it first."
        exit 1
    fi
}

check_storage_link() {
    if [ ! -L "$BASE_DIR/public/storage" ]; then
        echo "⚠️  Warning: storage symlink not found (public/storage)."
        echo "   Run: php artisan storage:link"
        echo "   Downloaded files won't be publicly accessible."
        echo ""
    fi
}

if [ $# -ne 1 ]; then
    echo "Usage: $0 <media-url>"
    echo ""
    echo "Examples:"
    echo "  $0 https://bouclepro.com/avatars/foo.png"
    echo "  $0 https://bouclepro.com/services/bar.jpg"
    exit 1
fi

URL="$1"

check_prereqs
check_storage_link

# Extract path after domain, strip query params
MEDIA_PATH=$(echo "$URL" | sed -E 's|^https?://[^/]+/||' | sed 's/\?.*$//')

if [ -z "$MEDIA_PATH" ]; then
    echo "Error: could not extract media path from URL."
    exit 1
fi

TARGET_FILE="$STORAGE_DIR/$MEDIA_PATH"
TARGET_DIR=$(dirname "$TARGET_FILE")

mkdir -p "$TARGET_DIR"

echo "→ Downloading: $URL"
echo "→ Target:      $TARGET_FILE"

if curl -fLo "$TARGET_FILE" "$URL" 2>/dev/null; then
    LOCAL_SIZE=$(du -h "$TARGET_FILE" | cut -f1)
    echo "✓ Success: $TARGET_FILE ($LOCAL_SIZE)"
else
    echo "✗ Error: download failed"
    rm -f "$TARGET_FILE"
    exit 1
fi
