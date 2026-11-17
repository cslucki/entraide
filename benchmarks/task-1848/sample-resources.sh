#!/bin/bash
# TASK-1848 — echantillonnage CPU/RAM (processus Apache mod_php = "app
# HTTP", processus `reverb:start` = "Reverb") + delta d'activite
# PostgreSQL (`pg_stat_database`, sans extension superuser — voir
# README.md "Limites de mesure", `pg_stat_statements` non disponible sur
# ce jumeau sans redemarrage serveur).
#
# Usage : ./sample-resources.sh <label> <output_dir>
# Ecrit <output_dir>/<label>.ps.jsonl (un echantillon par ligne, toutes
# les 2s jusqu'a interruption SIGTERM) + capture pg_stat_database au
# lancement (reprise AVANT la fenetre) et a l'arret (APRES).

set -euo pipefail

LABEL="${1:?usage: sample-resources.sh <label> <output_dir>}"
OUT_DIR="${2:?usage: sample-resources.sh <label> <output_dir>}"
BASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

mkdir -p "$OUT_DIR"
PS_FILE="$OUT_DIR/${LABEL}.ps.jsonl"
: > "$PS_FILE"

pg_env() {
    export PGPASSWORD="$(grep '^DB_PASSWORD=' "$BASE_DIR/.env" | cut -d= -f2-)"
}

pg_snapshot() {
    pg_env
    psql -h 127.0.0.1 -U "$(grep '^DB_USERNAME=' "$BASE_DIR/.env" | cut -d= -f2-)" \
        -d "$(grep '^DB_DATABASE=' "$BASE_DIR/.env" | cut -d= -f2-)" \
        -t -A -F',' \
        -c "SELECT xact_commit, xact_rollback, tup_returned, tup_fetched, blks_hit, blks_read FROM pg_stat_database WHERE datname = current_database();"
}

echo "pg_before,$(pg_snapshot)" >> "$PS_FILE"

cleanup() {
    echo "pg_after,$(pg_snapshot)" >> "$PS_FILE"
    exit 0
}
trap cleanup TERM INT

while true; do
    TS=$(date +%s%3N)
    # Workers Apache (mod_php, www-data) = "app HTTP". Process reverb:start = "Reverb".
    APACHE=$(ps -o pid,%cpu,rss,comm -C apache2 2>/dev/null | awk 'NR>1{cpu+=$2; rss+=$3; n++} END{printf "%.1f,%d,%d", cpu, rss, n}')
    REVERB=$(ps -o pid,%cpu,rss,cmd -e 2>/dev/null | grep 'reverb:start' | grep -v grep | awk '{cpu+=$2; rss+=$3} END{printf "%.1f,%d", cpu+0, rss+0}')
    echo "{\"t\":${TS},\"apache_cpu_pct\":${APACHE%%,*},\"apache_rss_kb\":$(echo $APACHE | cut -d, -f2),\"apache_workers\":$(echo $APACHE | cut -d, -f3),\"reverb_cpu_pct\":${REVERB%%,*},\"reverb_rss_kb\":$(echo $REVERB | cut -d, -f2)}" >> "$PS_FILE"
    sleep 2
done
