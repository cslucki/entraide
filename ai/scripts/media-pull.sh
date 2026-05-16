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
        echo "Error: 'curl' not found."
        exit 1
    fi
}

check_storage_link() {
    if [ ! -L "$BASE_DIR/public/storage" ]; then
        echo "⚠️  Warning: storage symlink not found (public/storage)."
        echo "   Run: php artisan storage:link"
        echo ""
    fi
}

url_decode() {
    if command -v python3 &>/dev/null; then
        python3 -c "import urllib.parse, sys; print(urllib.parse.unquote(sys.argv[1]))" "$1"
    else
        echo "$1"
    fi
}

if [ $# -ne 1 ]; then
    echo "Usage: $0 <media-url>"
    exit 1
fi

URL="$1"

check_prereqs
check_storage_link

MEDIA_PATH=$(echo "$URL" | sed -E 's|^https?://[^/]+/||' | sed 's/\?.*$//')
MEDIA_PATH=$(url_decode "$MEDIA_PATH")

if [ -z "$MEDIA_PATH" ]; then
    echo "Error: could not extract media path from URL."
    exit 1
fi

TARGET_FILE="$STORAGE_DIR/$MEDIA_PATH"
TARGET_DIR=$(dirname "$TARGET_FILE")

mkdir -p "$TARGET_DIR"

if [ ! -w "$TARGET_DIR" ]; then
    echo "✗ Error: cannot write to $TARGET_DIR"
    echo "  Fix: sudo chown -R \$USER $STORAGE_DIR"
    exit 1
fi

echo "→ Downloading: $URL"

if curl -fLo "$TARGET_FILE" "$URL" 2>/dev/null; then
    LOCAL_SIZE=$(du -h "$TARGET_FILE" | cut -f1)
    echo "✓ $MEDIA_PATH ($LOCAL_SIZE)"
else
    echo "✗ Error: download failed"
    rm -f "$TARGET_FILE"
    exit 1
fi
