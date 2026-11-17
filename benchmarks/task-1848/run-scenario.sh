#!/bin/bash
# TASK-1848 — orchestre UN scenario de mesure (une config A|B, une echelle
# S1|S2|S3) : demarre l'echantillonnage ressources, les clients WS (config
# B seulement) et les onglets HTTP reels, declenche des rafales de
# messages REELS a mi-fenetre, puis agrege les compteurs avant/apres.
#
# Usage : ./run-scenario.sh <A|B> <count> <duration_s> <out_dir> <label>

set -euo pipefail

CONFIG="${1:?A ou B}"
COUNT="${2:?nombre utilisateurs simules}"
DURATION="${3:?duree en secondes}"
OUT_DIR="${4:?repertoire de sortie}"
LABEL="${5:?etiquette (ex: A-S1)}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
ACCESS_LOG="/var/log/apache2/test.laravel_ssl_access.log"
REVERB_KEY="$(grep '^REVERB_APP_KEY=' "$BASE_DIR/.env" | cut -d= -f2-)"

mkdir -p "$OUT_DIR"
echo "=== $LABEL : config=$CONFIG count=$COUNT duration=${DURATION}s ==="

LINES_BEFORE=$(wc -l < "$ACCESS_LOG" 2>/dev/null || echo 0)
BYTES_BEFORE=$(stat -c %s "$ACCESS_LOG" 2>/dev/null || echo 0)

"$SCRIPT_DIR/sample-resources.sh" "$LABEL" "$OUT_DIR" &
SAMPLER_PID=$!

PIDS=()

if [ "$CONFIG" = "B" ]; then
    node "$SCRIPT_DIR/ws-clients.mjs" --count="$COUNT" --duration="$DURATION" --config=B \
        --reverbKey="$REVERB_KEY" --out="$OUT_DIR/$LABEL.ws.jsonl" \
        > "$OUT_DIR/$LABEL.ws.log" 2>&1 &
    PIDS+=($!)
fi

node "$SCRIPT_DIR/http-polling.mjs" --count="$COUNT" --duration="$DURATION" \
    > "$OUT_DIR/$LABEL.http.log" 2>&1 &
PIDS+=($!)

# Rafales de messages a mi-fenetre : loop (5), paire 0 en direct (3), paire
# 1 en entraide (3) — identique en A et en B (comparaison a volume constant).
sleep "$(( DURATION / 2 ))"
php "$SCRIPT_DIR/fire-messages.php" --role=loop --count=5 --intervalMs=400 \
    --out="$OUT_DIR/$LABEL.sent-loop.jsonl" > "$OUT_DIR/$LABEL.fire.log" 2>&1 || true
php "$SCRIPT_DIR/fire-messages.php" --role=direct --pairs=0 --count=3 --intervalMs=400 \
    --out="$OUT_DIR/$LABEL.sent-direct.jsonl" >> "$OUT_DIR/$LABEL.fire.log" 2>&1 || true
php "$SCRIPT_DIR/fire-messages.php" --role=entraide --pairs=1 --count=3 --intervalMs=400 \
    --out="$OUT_DIR/$LABEL.sent-entraide.jsonl" >> "$OUT_DIR/$LABEL.fire.log" 2>&1 || true

for pid in "${PIDS[@]}"; do
    wait "$pid" || true
done

kill -TERM "$SAMPLER_PID" 2>/dev/null || true
wait "$SAMPLER_PID" 2>/dev/null || true

BYTES_AFTER=$(stat -c %s "$ACCESS_LOG" 2>/dev/null || echo 0)

# Fenetre EXACTE de ce scenario : les octets AJOUTES pendant cette duree
# (jamais un delta de lignes brut, qui melangerait la navigation reelle de
# Cyril sur le MEME Apache partage avec le trafic de ce scenario).
WINDOW_LOG="$OUT_DIR/$LABEL.access-window.log"
tail -c "+$(( BYTES_BEFORE + 1 ))" "$ACCESS_LOG" | head -c "$(( BYTES_AFTER - BYTES_BEFORE ))" > "$WINDOW_LOG" 2>/dev/null || true

# N'est RETENU que le trafic identifiable comme ce scenario : Referer
# portant le slug de l'Organization de benchmark (onglets Playwright REELS)
# ou User-Agent "node" (clients WS/auth, qui ne font QUE login+broadcasting/
# auth, jamais de wire:poll). Tout le reste (navigation de Cyril en
# parallele sur une AUTRE Organization) est exclu.
TOTAL_WINDOW=$(wc -l < "$WINDOW_LOG" 2>/dev/null || echo 0)
OURS=$(grep -c -E 'benchmark-task-1848|"[^"]*node"$' "$WINDOW_LOG" 2>/dev/null || echo 0)
LIVEWIRE_OURS=$(grep -E 'benchmark-task-1848' "$WINDOW_LOG" | grep -c "livewire" 2>/dev/null || echo 0)

echo "{\"label\":\"$LABEL\",\"config\":\"$CONFIG\",\"count\":$COUNT,\"duration_s\":$DURATION,\"apache_requests_total_window\":$TOTAL_WINDOW,\"apache_requests_ours\":$OURS,\"apache_livewire_update_ours\":$LIVEWIRE_OURS}" \
    | tee "$OUT_DIR/$LABEL.summary.json"

echo "=== $LABEL termine ==="
