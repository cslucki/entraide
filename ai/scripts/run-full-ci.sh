#!/bin/bash

# =========================================================
# RUN FULL CI — demander les gates finaux d'une TASK
# =========================================================
# Usage:
#   ./ai/scripts/run-full-ci.sh [--dry-run] [--wait] [--allow-protected]
#
# La commande standard pour demander les deux suites completes sur un SHA
# precis : relance ciblee, verification d'un HEAD final, diagnostic.
#
# ATTENTION — ce script ne remplace PAS les declencheurs automatiques.
#
# TASK-1148 voulait retirer `develop` des declencheurs pour ne payer les suites
# qu'une fois sur le HEAD final. La mesure faite sur la PR #214 l'a refuse : une
# paire lancee par `workflow_dispatch` produit bien les deux check-runs verts
# sur le SHA exact, mais GitHub ne les fait pas remonter dans le
# `statusCheckRollup` de la PR — `gh pr checks` repond « no checks reported » et
# le merge state reste `BLOCKED`. Le ruleset « Required CI checks » n'est donc
# pas satisfait. Les declencheurs automatiques sont conserves.
#
# Ce script reste utile pour obtenir une paire a la demande sur un SHA donne.
#
# Ce que ce script garantit :
#   - la branche est bien une branche de TASK (jamais develop/main par defaut) ;
#   - le depot est propre ;
#   - le HEAD local est REELLEMENT pousse sur origin ;
#   - les deux runs declenches portent sur le SHA EXACT — jamais un autre.
#
# Ce dernier point est le coeur : `merge-task.sh` refuse de merger tant que les
# deux gates ne sont pas verts sur le SHA du commit a merger. Un run vert sur un
# commit voisin ne prouve rien, et ce script n'en accepte aucun.
# =========================================================

set -uo pipefail

BASE_DIR="${RUN_FULL_CI_BASE_DIR:-/home/cyril/claude-code/sites/test.laravel}"
WORKFLOWS=("ci-sqlite.yml" "ci-postgresql.yml")
NOMS_ATTENDUS=("SQLite CI" "PostgreSQL CI")

DRY_RUN=false
WAIT=false
ALLOW_PROTECTED=false

rouge() { printf '\033[31m%s\033[0m\n' "$1"; }
vert() { printf '\033[32m%s\033[0m\n' "$1"; }
jaune() { printf '\033[33m%s\033[0m\n' "$1"; }

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=true ;;
        --wait) WAIT=true ;;
        --allow-protected) ALLOW_PROTECTED=true ;;
        -h|--help)
            sed -n '3,30p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            rouge "Option inconnue : $arg"
            echo "Usage: ./ai/scripts/run-full-ci.sh [--dry-run] [--wait] [--allow-protected]"
            exit 1
            ;;
    esac
done

cd "$BASE_DIR" || { rouge "Repertoire introuvable : $BASE_DIR"; exit 1; }

echo ""
echo "====================================="
echo "RUN FULL CI"
echo "====================================="
echo ""

# ── 1. Outils ────────────────────────────────────────────────────────────────
if ! command -v gh &> /dev/null; then
    rouge "ERREUR : le CLI gh est absent — impossible de declencher un workflow."
    exit 1
fi

# ── 2. Branche ───────────────────────────────────────────────────────────────
BRANCHE=$(git branch --show-current)

if [ -z "$BRANCHE" ]; then
    rouge "ERREUR : HEAD detache. Se placer sur la branche de la TASK."
    exit 1
fi

if [ "$BRANCHE" = "main" ] || [ "$BRANCHE" = "develop" ]; then
    if [ "$ALLOW_PROTECTED" = false ]; then
        rouge "ERREUR : « $BRANCHE » n'est pas une branche de TASK."
        echo ""
        echo "  Ces gates se demandent sur le HEAD final d'une TASK, pas sur"
        echo "  l'integration. Si c'est reellement voulu :"
        echo ""
        echo "    ./ai/scripts/run-full-ci.sh --allow-protected"
        echo ""
        exit 1
    fi
    jaune "  Branche d'integration acceptee explicitement (--allow-protected)."
fi

echo "Branche : $BRANCHE"

# ── 3. Depot propre ──────────────────────────────────────────────────────────
PORCELAIN=$(git status --porcelain)

if [ -n "$PORCELAIN" ]; then
    rouge "ERREUR : changements non commites."
    echo ""
    git status --short
    echo ""
    echo "  Un gate ne prouve que ce qui est POUSSE. Commiter d'abord."
    echo ""
    exit 1
fi

echo "Depot   : propre"

# ── 4. Le HEAD local est-il pousse ? ─────────────────────────────────────────
SHA=$(git rev-parse HEAD)

git fetch origin "$BRANCHE" --quiet 2>/dev/null

SHA_DISTANT=$(git rev-parse "origin/$BRANCHE" 2>/dev/null || echo "")

if [ -z "$SHA_DISTANT" ]; then
    rouge "ERREUR : « $BRANCHE » n'existe pas sur origin."
    echo ""
    echo "  git push -u origin $BRANCHE"
    echo ""
    exit 1
fi

if [ "$SHA" != "$SHA_DISTANT" ]; then
    rouge "ERREUR : le HEAD local n'est pas celui d'origin."
    echo ""
    echo "  local  : $SHA"
    echo "  origin : $SHA_DISTANT"
    echo ""
    echo "  GitHub ne peut tester que ce qu'il a recu. Pousser d'abord."
    echo ""
    exit 1
fi

echo "HEAD    : $SHA"
echo ""

# ── 5. Dry-run ───────────────────────────────────────────────────────────────
if [ "$DRY_RUN" = true ]; then
    echo "====================================="
    echo "DRY-RUN — rien n'est declenche"
    echo "====================================="
    echo ""
    echo "  Serait declenche sur la branche « $BRANCHE » :"
    for w in "${WORKFLOWS[@]}"; do
        echo "    gh workflow run $w --ref $BRANCHE"
    done
    echo ""
    echo "  Les runs seraient ensuite verifies sur le SHA exact :"
    echo "    $SHA"
    echo ""
    exit 0
fi

# ── 6. Declenchement ─────────────────────────────────────────────────────────
# L'horodatage sert a ne retenir que les runs nes de CETTE demande : une
# relance manuelle plus ancienne, sur le meme SHA, ne doit pas etre prise pour
# une preuve fraiche.
DEPART=$(date -u +%Y-%m-%dT%H:%M:%SZ)

echo "Declenchement des deux suites completes..."
echo ""

for w in "${WORKFLOWS[@]}"; do
    if gh workflow run "$w" --ref "$BRANCHE" 2>/dev/null; then
        echo "  demande : $w"
    else
        rouge "  ECHEC du declenchement : $w"
        exit 1
    fi
done

echo ""
echo "Attente de l'enregistrement des runs par GitHub..."

# ── 7. Retrouver les deux runs, sur le SHA EXACT ─────────────────────────────
trouver_run() {
    local nom="$1"
    gh run list --branch "$BRANCHE" --commit "$SHA" --workflow "$2" \
        --event workflow_dispatch --limit 5 \
        --json databaseId,createdAt,headSha,name,status,conclusion,url 2>/dev/null \
        | python3 -c "
import json, sys
try:
    runs = json.load(sys.stdin)
except Exception:
    sys.exit()
depart = '$DEPART'
sha = '$SHA'
# Le SHA d'abord : un run sur un autre commit ne prouve rien de celui-ci.
candidats = [r for r in runs if r.get('headSha') == sha and r.get('createdAt', '') >= depart]
if candidats:
    r = sorted(candidats, key=lambda x: x.get('createdAt', ''))[-1]
    print('%s|%s|%s' % (r['databaseId'], r['url'], r.get('headSha', '')))
"
}

ID_SQLITE=""; URL_SQLITE=""
ID_PG=""; URL_PG=""

for tentative in $(seq 1 20); do
    sleep 3

    if [ -z "$ID_SQLITE" ]; then
        LIGNE=$(trouver_run "SQLite CI" "ci-sqlite.yml")
        if [ -n "$LIGNE" ]; then
            ID_SQLITE="${LIGNE%%|*}"
            URL_SQLITE=$(echo "$LIGNE" | cut -d'|' -f2)
        fi
    fi

    if [ -z "$ID_PG" ]; then
        LIGNE=$(trouver_run "PostgreSQL CI" "ci-postgresql.yml")
        if [ -n "$LIGNE" ]; then
            ID_PG="${LIGNE%%|*}"
            URL_PG=$(echo "$LIGNE" | cut -d'|' -f2)
        fi
    fi

    [ -n "$ID_SQLITE" ] && [ -n "$ID_PG" ] && break
done

if [ -z "$ID_SQLITE" ] || [ -z "$ID_PG" ]; then
    rouge "ERREUR : les deux runs n'ont pas ete retrouves sur le SHA exact."
    echo ""
    echo "  SHA attendu : $SHA"
    echo "  SQLite      : ${ID_SQLITE:-INTROUVABLE}"
    echo "  PostgreSQL  : ${ID_PG:-INTROUVABLE}"
    echo ""
    echo "  Aucun run portant un autre SHA n'est accepte : verifier a la main."
    echo "    gh run list --branch $BRANCHE"
    echo ""
    exit 1
fi

echo ""
echo "====================================="
echo "RUNS DECLENCHES"
echo "====================================="
echo ""
echo "  SHA        : $SHA"
echo "  SQLite     : $ID_SQLITE"
echo "               $URL_SQLITE"
echo "  PostgreSQL : $ID_PG"
echo "               $URL_PG"
echo ""

if [ "$WAIT" = false ]; then
    echo "  Suivre :  gh run watch $ID_PG"
    echo "  Ou relancer ce script avec --wait."
    echo ""
    exit 0
fi

# ── 8. Attente du verdict ────────────────────────────────────────────────────
echo "Attente des deux verdicts (--wait)..."
echo ""

attendre() {
    local id="$1" libelle="$2"
    for _ in $(seq 1 240); do
        local etat conclusion
        etat=$(gh run view "$id" --json status --jq '.status' 2>/dev/null || echo "")
        if [ "$etat" = "completed" ]; then
            conclusion=$(gh run view "$id" --json conclusion --jq '.conclusion' 2>/dev/null || echo "")
            echo "$conclusion"
            return 0
        fi
        sleep 15
    done
    echo "timeout"
}

CONCLUSION_SQLITE=$(attendre "$ID_SQLITE" "SQLite")
echo "  SQLite     : $CONCLUSION_SQLITE"

CONCLUSION_PG=$(attendre "$ID_PG" "PostgreSQL")
echo "  PostgreSQL : $CONCLUSION_PG"

echo ""

if [ "$CONCLUSION_SQLITE" = "success" ] && [ "$CONCLUSION_PG" = "success" ]; then
    vert "====================================="
    vert "LES DEUX GATES SONT VERTS"
    vert "====================================="
    echo ""
    echo "  Sur le SHA : $SHA"
    echo ""
    echo "  merge-task.sh acceptera ce commit. Tout commit ajoute ensuite"
    echo "  change le HEAD et exige une nouvelle paire."
    echo ""
    exit 0
fi

rouge "====================================="
rouge "GATE NON VERT"
rouge "====================================="
echo ""
echo "  SQLite     : $CONCLUSION_SQLITE"
echo "  PostgreSQL : $CONCLUSION_PG"
echo ""
echo "  Diagnostiquer avant de relancer :"
echo "    gh run view $ID_SQLITE --log-failed"
echo "    gh run view $ID_PG --log-failed"
echo ""
exit 1
