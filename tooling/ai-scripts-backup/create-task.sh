#!/bin/bash

# =========================================================
# CREATE TASK SCRIPT
# =========================================================
# Fichier UNIQUE a deux emplacements, BYTE-IDENTIQUES (TASK-1293) :
#   - ai/scripts/create-task.sh                : copie OPERATIONNELLE
#     (hors git — `ai/` est gitignore —, executable) : celle que les
#     agents lancent au quotidien ;
#   - tooling/ai-scripts-backup/create-task.sh : copie TRACKEE de secours
#     (sans bit +x), auditee en PR et exercee par la suite UNIQUE
#     tooling/ai-scripts-backup/tests/create-task-smoke.sh.
# Les deux emplacements ont la MEME profondeur relative : BASE_DIR
# (SCRIPT_DIR/../..) resout la racine du depot depuis l'un comme l'autre.
# CREATE_TASK_BASE_DIR reste accepte en derogation explicite (tests).
#
# NUMEROTATION (TASK-1293) : allocation GLOBALE, NEXT = MAX_GLOBAL + 1.
# MAX_GLOBAL est lu sur l'UNION en lecture seule de quatre sources
# (aucune n'est suffisante seule — coeur du correctif ex-TASK-1201,
# conserve) :
#   - TODO/ et TODO/ARCHIVES/ (une TASK archivee reste consommee) ;
#   - refs git locales / remotes / tags ;
#   - historique des merges ;
#   - fichiers git trackes.
# Les plages produit (1130-1199) / rag (1200-1999), l'option --range et le
# fichier TODO/.task-range sont SUPPRIMES : une seule ligne de numeros.
# --range=* est refuse avec une erreur explicite, jamais un silence.
# =========================================================

set -e

# =========================================================
# CONFIG
# =========================================================

BASE_DIR="${CREATE_TASK_BASE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"

TODO_DIR="$BASE_DIR/TODO"
TEMPLATE_FILE="$BASE_DIR/ai/tasks/templates/TASK_TEMPLATE.md"

usage() {
  echo ""
  echo "Usage:"
  echo "  ./create-task.sh \"Task title\" [OWNER] [--subtask T###.##]"
  echo ""
  echo "  OWNER par defaut : GLM."
  echo "  Numerotation : NEXT = MAX_GLOBAL + 1 (union de TODO/, TODO/ARCHIVES/,"
  echo "  refs git, historique des merges, fichiers trackes)."
  echo ""
  echo "Options (ordre libre, --opt valeur ou --opt=valeur) :"
  echo "  --subtask T###.##   mode sous-tache (ex: T074.1A)"
  echo "  --help, -h          affiche cette aide et ne cree RIEN"
  echo ""
  echo "Examples:"
  echo "  ./create-task.sh \"Fix navbar mobile bug\" GLM"
  echo "  ./create-task.sh \"ChatLoop interactions\" OPENCODE --subtask T074.1A"
  echo ""
}

# =========================================================
# ARGUMENTS (TASK-1293)
# =========================================================
# Ordre libre ; --opt valeur et --opt=valeur acceptes ; option inconnue =
# erreur explicite ; --help / -h affichent l'aide et ne creent JAMAIS rien
# (l'ancien parseur les prenait pour un titre et creait une TASK).

TITLE=""
OWNER=""
SUBTASK=""

while [ $# -gt 0 ]; do
  case "$1" in
    --help|-h)
      usage
      exit 0
      ;;
    --subtask=*)
      SUBTASK="${1#*=}"
      ;;
    --subtask)
      if [ -z "${2:-}" ]; then
        echo ""
        echo "ERROR: --subtask requiert une valeur (ex: --subtask T074.1A)."
        echo ""
        exit 1
      fi
      SUBTASK="$2"
      shift
      ;;
    --range|--range=*)
      echo ""
      echo "ERROR: les plages d'identifiants sont supprimees (TASK-1293)."
      echo "  La numerotation est globale : NEXT = MAX_GLOBAL + 1, sans option."
      echo ""
      exit 1
      ;;
    -*)
      echo ""
      echo "ERROR: option inconnue '$1'."
      usage
      exit 1
      ;;
    *)
      if [ -z "$TITLE" ]; then
        TITLE="$1"
      elif [ -z "$OWNER" ]; then
        OWNER="$1"
      else
        echo ""
        echo "ERROR: argument positionnel inattendu '$1' (attendus: \"Task title\" puis OWNER)."
        echo ""
        exit 1
      fi
      ;;
  esac
  shift
done

if [ -z "$TITLE" ]; then
  usage
  exit 1
fi

if [ -z "$OWNER" ]; then
  OWNER="GLM"
fi

# =========================================================
# FAIL-CLOSED (TASK-1293) — AVANT TOUTE CREATION
# =========================================================
# Une TASK nait de develop, avec un worktree propre. Partir d'une autre
# branche empile les branches l'une sur l'autre ; partir d'un worktree sale
# embarque des changements etrangers dans la nouvelle branche. Dans les
# deux cas : refus explicite, et RIEN n'est cree.

CURRENT_BRANCH="$(git -C "$BASE_DIR" symbolic-ref --short -q HEAD || true)"

if [ "$CURRENT_BRANCH" != "develop" ]; then
  echo ""
  echo "ERROR: la branche courante est '${CURRENT_BRANCH:-DETACHED HEAD}', pas 'develop'."
  echo "  Reviens sur develop avant de creer une TASK. Rien n'a ete cree."
  echo ""
  exit 1
fi

if [ -n "$(git -C "$BASE_DIR" status --porcelain 2>/dev/null)" ]; then
  echo ""
  echo "ERROR: le worktree n'est pas propre ('git status --porcelain' non vide)."
  echo "  Commit ou restaure ces changements avant de creer une TASK."
  echo "  Rien n'a ete cree."
  echo ""
  exit 1
fi

# =========================================================
# TIMESTAMP
# =========================================================

NOW="$(date '+%Y-%m-%d %H:%M:%S') Europe/Paris"

# =========================================================
# TASK ID + FILE + BRANCH (standard or --subtask mode)
# =========================================================

if [ -n "$SUBTASK" ]; then
  # Validate subtask format: T###.##
  if ! [[ "$SUBTASK" =~ ^T([0-9]+)\.([A-Za-z0-9]+)$ ]]; then
    echo ""
    echo "ERROR: Invalid subtask format '$SUBTASK'. Expected T###.## (e.g. T074.1A)."
    echo ""
    exit 1
  fi

  NUM="${BASH_REMATCH[1]}"
  SUFFIX="${BASH_REMATCH[2]}"
  SUFFIX_LOWER=$(echo "$SUFFIX" | tr '[:upper:]' '[:lower:]')
  NUM_LOWER=$(echo "$NUM" | tr '[:upper:]' '[:lower:]')
  SUBTASK_SLUG="t${NUM_LOWER}-${SUFFIX_LOWER}"

  TASK_ID="TASK-${NUM}.${SUFFIX}"

  SLUG=$(echo "$TITLE" \
    | tr '[:upper:]' '[:lower:]' \
    | sed 's/[^a-z0-9]/-/g' \
    | sed 's/-\+/-/g' \
    | sed 's/^-//' \
    | sed 's/-$//')

  FILE_NAME="TASK-${NUM}-${SUBTASK_SLUG}-${SLUG}.md"
  TASK_FILE="$TODO_DIR/$FILE_NAME"
  BRANCH_NAME="${SUBTASK}-${SUBTASK_SLUG}-${SLUG}"

  # Refuse if file already exists
  if [ -f "$TASK_FILE" ]; then
    echo ""
    echo "ERROR: Task file already exists:"
    echo "  $TASK_FILE"
    echo ""
    exit 1
  fi

  # Refuse if branch already exists (local or remote)
  if git -C "$BASE_DIR" show-ref --verify --quiet "refs/heads/$BRANCH_NAME" 2>/dev/null; then
    echo ""
    echo "ERROR: Branch already exists locally: $BRANCH_NAME"
    echo ""
    exit 1
  fi

  if git -C "$BASE_DIR" show-ref --verify --quiet "refs/remotes/origin/$BRANCH_NAME" 2>/dev/null; then
    echo ""
    echo "ERROR: Branch already exists on origin: $BRANCH_NAME"
    echo ""
    exit 1
  fi

else
  # =======================================================================
  # Mode standard : allocation GLOBALE (TASK-1293) — NEXT = MAX_GLOBAL + 1
  # =======================================================================
  #
  # L'ancienne regle etait `max(TODO/) + 1` : elle reattribuait des numeros
  # deja consommes des que TODO/ avait ete purge de ses taches archivees —
  # c'est ce qui a fait naitre deux TASK-1131 et deux TASK-1132. Les plages
  # produit/rag qui l'ont remplacee ont cree l'effet inverse : allouer en
  # ARRIERE du maximum global. Une seule regle subsiste : le plus grand
  # identifiant CONSOMME, toutes sources confondues, plus un.
  #
  # Aucune source n'est suffisante SEULE, et c'est le coeur du correctif :
  #   - les refs ignorent les branches supprimees apres merge ;
  #   - les merges ignorent les taches en cours, jamais mergees ;
  #   - les fichiers versionnes ignorent les taches sans test ;
  #   - `TODO/` est purge, et propre a chaque worktree.
  # On prend donc l'UNION des quatre, en lecture seule : aucune tache
  # historique n'est ouverte, deplacee ni renumerotee.

  ids_consommes() {
    find "$TODO_DIR" "$TODO_DIR/ARCHIVES" -maxdepth 1 -name "TASK-*.md" 2>/dev/null | grep -oE 'TASK-[0-9]+'
    git -C "$BASE_DIR" for-each-ref --format='%(refname:short)' refs/heads refs/remotes refs/tags 2>/dev/null | grep -oE 'TASK-[0-9]+'
    git -C "$BASE_DIR" log --merges --format=%s --all 2>/dev/null | grep -oE 'TASK-[0-9]+'
    git -C "$BASE_DIR" ls-files 2>/dev/null | grep -oE 'TASK-?[0-9]+' | sed 's/TASK\([0-9]\)/TASK-\1/'
  }

  LAST_TASK=$(ids_consommes \
    | grep -oE '[0-9]+$' \
    | sort -n \
    | tail -1)

  # Fail-closed : un depot sans AUCUN identifiant TASK n'est pas le depot
  # attendu — on n'invente pas un point de depart en silence.
  if [ -z "$LAST_TASK" ]; then
    echo ""
    echo "ERROR: aucun identifiant TASK-* trouve (TODO/, ARCHIVES/, refs,"
    echo "  merges, fichiers trackes). Depot inattendu : rien n'a ete cree."
    echo ""
    exit 1
  fi

  NEXT_TASK=$((10#$LAST_TASK + 1))

  TASK_ID=$(printf "TASK-%03d" "$NEXT_TASK")

  SLUG=$(echo "$TITLE" \
    | tr '[:upper:]' '[:lower:]' \
    | sed 's/[^a-z0-9]/-/g' \
    | sed 's/-\+/-/g' \
    | sed 's/^-//' \
    | sed 's/-$//')

  FILE_NAME="${TASK_ID}-${SLUG}.md"
  TASK_FILE="$TODO_DIR/$FILE_NAME"
  BRANCH_NAME="${TASK_ID}-${SLUG}"

  # Les memes gardes que le mode --subtask, qui les avait deja : le mode
  # standard, lui, n'en avait AUCUNE et ecrasait sans rien dire.
  if [ -f "$TASK_FILE" ]; then
    echo ""
    echo "ERROR: Task file already exists:"
    echo "  $TASK_FILE"
    echo ""
    exit 1
  fi

  if git -C "$BASE_DIR" show-ref --verify --quiet "refs/heads/$BRANCH_NAME" 2>/dev/null; then
    echo ""
    echo "ERROR: Branch already exists locally: $BRANCH_NAME"
    echo ""
    exit 1
  fi

  if git -C "$BASE_DIR" show-ref --verify --quiet "refs/remotes/origin/$BRANCH_NAME" 2>/dev/null; then
    echo ""
    echo "ERROR: Branch already exists on origin: $BRANCH_NAME"
    echo ""
    exit 1
  fi
fi

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

# `-C "$BASE_DIR"` et non le repertoire courant : sans lui, le script creait la
# branche la ou il etait lance. Lance depuis ailleurs — un autre worktree, ou
# une suite de tests — il ecrivait dans le mauvais depot, silencieusement.
git -C "$BASE_DIR" checkout -b "$BRANCH_NAME"

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
