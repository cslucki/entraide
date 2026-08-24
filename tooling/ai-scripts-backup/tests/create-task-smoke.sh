#!/bin/bash

# =========================================================
# SMOKE TESTS — create-task.sh (TASK-1293)
# =========================================================
# SUITE UNIQUE, PARAMETRABLE (decision Cyril, T1293) : ce fichier est LA
# seule logique de test — deux suites qui peuvent diverger sont interdites.
# Par defaut, elle exerce la copie TRACKEE
# (tooling/ai-scripts-backup/create-task.sh) ; pour exercer la copie
# OPERATIONNELLE locale, on parametre la CIBLE, on ne duplique pas le
# fichier :
#
#   bash tooling/ai-scripts-backup/tests/create-task-smoke.sh
#   CREATE_TASK_SCRIPT="$PWD/ai/scripts/create-task.sh" \
#     bash tooling/ai-scripts-backup/tests/create-task-smoke.sh
#
# Chaque test tourne dans un BANC JETABLE (mktemp -d) : depot git
# `init -b develop` + TODO/ + TODO/ARCHIVES/ + template, pilote via
# CREATE_TASK_BASE_DIR (la derogation prevue par le script). RIEN n'est
# ecrit dans le TODO/ reel ni dans le depot reel — le banc porte son
# propre .gitignore (TODO/, ai/) qui reproduit celui du vrai depot, sans
# quoi la garde « worktree propre » se declencherait sur ses propres
# fichiers de banc.
#
# Usage: bash tooling/ai-scripts-backup/tests/create-task-smoke.sh
# Exit 0 si tout passe, exit 1 sinon (detail des echecs imprime).
# =========================================================

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SUT_RAW="${CREATE_TASK_SCRIPT:-$SCRIPT_DIR/../create-task.sh}"
SUT="$(cd "$(dirname "$SUT_RAW")" && pwd)/$(basename "$SUT_RAW")"
TEMPLATE_SRC="$SCRIPT_DIR/../templates/TASK_TEMPLATE.md"

if [ ! -f "$SUT" ]; then
  echo "ERROR: script cible introuvable: $SUT" >&2
  exit 1
fi

echo "Cible testee : $SUT"
echo ""

PASS_COUNT=0
FAIL_COUNT=0
FAILURES=()

pass() { PASS_COUNT=$((PASS_COUNT + 1)); echo "  PASS: $1"; }
fail() { FAIL_COUNT=$((FAIL_COUNT + 1)); FAILURES+=("$1"); echo "  FAIL: $1"; }

BENCHES=()
cleanup() {
  local b
  for b in "${BENCHES[@]:-}"; do
    [ -n "$b" ] && [ -d "$b" ] && rm -rf "$b"
  done
}
trap cleanup EXIT

# Banc jetable : depot git develop + arborescence attendue par le script.
make_bench() {
  local bench
  bench=$(mktemp -d "${TMPDIR:-/tmp}/create-task-bench.XXXXXX")
  BENCHES+=("$bench")
  git -C "$bench" init -q -b develop
  git -C "$bench" config user.email bench@test.invalid
  git -C "$bench" config user.name Bench
  git -C "$bench" config commit.gpgsign false
  mkdir -p "$bench/TODO/ARCHIVES" "$bench/ai/tasks/templates"
  cp "$TEMPLATE_SRC" "$bench/ai/tasks/templates/TASK_TEMPLATE.md"
  printf 'TODO/\nai/\n' > "$bench/.gitignore"
  echo bench > "$bench/README.md"
  git -C "$bench" add .gitignore README.md
  git -C "$bench" commit -qm "init bench"
  echo "$bench"
}

OUT=""
# run_sut <bench> [args...] — execute la cible avec CREATE_TASK_BASE_DIR
# pointe sur le banc ; $OUT recoit stdout+stderr, le code de retour est
# celui du script. RUN_CWD (optionnel) change le repertoire d'execution.
run_sut() {
  local bench="$1"
  shift
  local rc=0
  OUT=$(cd "${RUN_CWD:-$bench}" && CREATE_TASK_BASE_DIR="$bench" bash "$SUT" "$@" 2>&1) || rc=$?
  return $rc
}

todo_count()   { find "$1/TODO" -maxdepth 1 -name 'TASK-*.md' 2>/dev/null | wc -l | tr -d ' '; }
branch_count() { git -C "$1" for-each-ref refs/heads --format='%(refname:short)' | wc -l | tr -d ' '; }
has_branch()   { git -C "$1" show-ref --verify --quiet "refs/heads/$2"; }

# ---------------------------------------------------------
# 1. --help ne cree rien
# ---------------------------------------------------------
echo "TEST 1: --help ne cree rien"
b=$(make_bench)
rc=0; run_sut "$b" --help || rc=$?
[ "$rc" -eq 0 ] && pass "--help exit 0" || fail "--help exit=$rc (attendu 0)"
echo "$OUT" | grep -q "Usage" && pass "--help affiche l'aide" || fail "--help n'affiche pas l'aide"
[ "$(todo_count "$b")" = "0" ] && pass "--help: aucun TASK file" || fail "--help a cree un TASK file"
[ "$(branch_count "$b")" = "1" ] && pass "--help: aucune branche creee" || fail "--help a cree une branche"
if echo "$OUT" | grep -qiE 'produit|rag|task-range'; then
  fail "l'aide mentionne encore produit/rag/task-range"
else
  pass "l'aide ne mentionne plus produit/rag/task-range"
fi
rc=0; run_sut "$b" "Titre quand meme" --help || rc=$?
{ [ "$rc" -eq 0 ] && [ "$(todo_count "$b")" = "0" ]; } \
  && pass "--help prime meme accompagne d'un titre" \
  || fail "--help accompagne d'un titre a cree quelque chose (rc=$rc)"

# ---------------------------------------------------------
# 2. -h ne cree rien
# ---------------------------------------------------------
echo "TEST 2: -h ne cree rien"
b=$(make_bench)
rc=0; run_sut "$b" -h || rc=$?
[ "$rc" -eq 0 ] && pass "-h exit 0" || fail "-h exit=$rc (attendu 0)"
echo "$OUT" | grep -q "Usage" && pass "-h affiche l'aide" || fail "-h n'affiche pas l'aide"
{ [ "$(todo_count "$b")" = "0" ] && [ "$(branch_count "$b")" = "1" ]; } \
  && pass "-h: aucun TASK file, aucune branche" \
  || fail "-h a cree un TASK file ou une branche"

# ---------------------------------------------------------
# 3. MAX_GLOBAL trouve dans TODO/
# ---------------------------------------------------------
echo "TEST 3: MAX_GLOBAL dans TODO/"
b=$(make_bench)
touch "$b/TODO/TASK-1300-seed-todo.md"
rc=0; run_sut "$b" "Nouvelle tache banc" FABLE || rc=$?
[ "$rc" -eq 0 ] && pass "creation exit 0" || fail "creation exit=$rc : $OUT"
echo "$OUT" | grep -q "Task ID: TASK-1301" && pass "next = 1301 (max 1300 dans TODO/)" || fail "next != 1301"
[ -f "$b/TODO/TASK-1301-nouvelle-tache-banc.md" ] && pass "TASK file cree" || fail "TASK file absent"
has_branch "$b" "TASK-1301-nouvelle-tache-banc" && pass "branche creee" || fail "branche absente"
grep -q "task_id: TASK-1301" "$b/TODO/TASK-1301-nouvelle-tache-banc.md" \
  && pass "frontmatter task_id correct" || fail "frontmatter task_id incorrect"

# ---------------------------------------------------------
# 4. MAX_GLOBAL trouve dans TODO/ARCHIVES/ (ex-T1201 conserve)
# ---------------------------------------------------------
echo "TEST 4: MAX_GLOBAL dans TODO/ARCHIVES/"
b=$(make_bench)
touch "$b/TODO/TASK-1200-en-cours.md"
touch "$b/TODO/ARCHIVES/TASK-1310-archivee.md"
rc=0; run_sut "$b" "Apres archive" FABLE || rc=$?
[ "$rc" -eq 0 ] && pass "creation exit 0" || fail "creation exit=$rc : $OUT"
echo "$OUT" | grep -q "Task ID: TASK-1311" \
  && pass "next = 1311 (une TASK archivee reste consommee)" \
  || fail "next != 1311 — la purge de TODO/ ferait reculer le compteur"

# ---------------------------------------------------------
# 5. MAX_GLOBAL trouve dans une ref (branche)
# ---------------------------------------------------------
echo "TEST 5: MAX_GLOBAL dans une ref"
b=$(make_bench)
git -C "$b" branch TASK-1320-ref-seed
rc=0; run_sut "$b" "Apres ref" FABLE || rc=$?
[ "$rc" -eq 0 ] && pass "creation exit 0" || fail "creation exit=$rc : $OUT"
echo "$OUT" | grep -q "Task ID: TASK-1321" \
  && pass "next = 1321 (branche TASK-1320 vue)" || fail "next != 1321"

# ---------------------------------------------------------
# 6. MAX_GLOBAL trouve dans un fichier tracke
# ---------------------------------------------------------
echo "TEST 6: MAX_GLOBAL dans un fichier git tracke"
b=$(make_bench)
mkdir -p "$b/docs"
echo notes > "$b/docs/TASK-1330-notes.md"
git -C "$b" add docs/TASK-1330-notes.md
git -C "$b" commit -qm "docs TASK-1330"
rc=0; run_sut "$b" "Apres fichier tracke" FABLE || rc=$?
[ "$rc" -eq 0 ] && pass "creation exit 0" || fail "creation exit=$rc : $OUT"
echo "$OUT" | grep -q "Task ID: TASK-1331" \
  && pass "next = 1331 (fichier tracke TASK-1330 vu)" || fail "next != 1331"

# ---------------------------------------------------------
# 7. Identifiants melanges 11xx/12xx : le plus grand GLOBAL gagne
# ---------------------------------------------------------
echo "TEST 7: 11xx et 12xx melanges — le max GLOBAL gagne"
b=$(make_bench)
touch "$b/TODO/TASK-1150-produit-legacy.md"
touch "$b/TODO/ARCHIVES/TASK-1299-rag-legacy.md"
rc=0; run_sut "$b" "Convergence numeros" FABLE || rc=$?
[ "$rc" -eq 0 ] && pass "creation exit 0" || fail "creation exit=$rc : $OUT"
echo "$OUT" | grep -q "Task ID: TASK-1300" \
  && pass "next = 1300, jamais 1151 (plus d'allocation en arriere du max)" \
  || fail "next != 1300 — une plage a refait surface"

# ---------------------------------------------------------
# 8. Apres TASK-1293 => next = 1294
# ---------------------------------------------------------
echo "TEST 8: apres TASK-1293, next = 1294"
b=$(make_bench)
touch "$b/TODO/TASK-1293-create-task-numerotation.md"
rc=0; run_sut "$b" "Tache suivante" FABLE || rc=$?
[ "$rc" -eq 0 ] && pass "creation exit 0" || fail "creation exit=$rc : $OUT"
echo "$OUT" | grep -q "Task ID: TASK-1294" && pass "next = 1294" || fail "next != 1294"

# ---------------------------------------------------------
# 9. Branche courante != develop => refus, aucun effet de bord
# ---------------------------------------------------------
echo "TEST 9: hors develop => refus"
b=$(make_bench)
touch "$b/TODO/TASK-1400-seed.md"
git -C "$b" checkout -q -b feature-en-cours
rc=0; run_sut "$b" "Ne doit pas naitre" FABLE || rc=$?
[ "$rc" -ne 0 ] && pass "refus exit non-zero" || fail "creation acceptee hors develop"
echo "$OUT" | grep -q "develop" && pass "message explicite (develop)" || fail "message non explicite"
[ "$(todo_count "$b")" = "1" ] && pass "aucun TASK file cree au refus" || fail "TASK file cree malgre le refus"
[ "$(branch_count "$b")" = "2" ] && pass "aucune branche creee au refus" || fail "branche creee malgre le refus"

# ---------------------------------------------------------
# 10. Worktree sale => refus, aucun effet de bord
# ---------------------------------------------------------
echo "TEST 10: worktree sale => refus"
b=$(make_bench)
touch "$b/TODO/TASK-1400-seed.md"
echo dirty >> "$b/README.md"
rc=0; run_sut "$b" "Ne doit pas naitre" FABLE || rc=$?
[ "$rc" -ne 0 ] && pass "refus exit non-zero" || fail "creation acceptee sur worktree sale"
echo "$OUT" | grep -qi "propre" && pass "message explicite (worktree)" || fail "message non explicite"
[ "$(todo_count "$b")" = "1" ] && pass "aucun TASK file cree au refus" || fail "TASK file cree malgre le refus"
[ "$(branch_count "$b")" = "1" ] && pass "aucune branche creee au refus" || fail "branche creee malgre le refus"

# ---------------------------------------------------------
# 11. --subtask T074.2 : comportement inchange
# ---------------------------------------------------------
echo "TEST 11: --subtask T074.2 inchange"
b=$(make_bench)
rc=0; run_sut "$b" "Audit ChatLoop" OPENCODE --subtask T074.2 || rc=$?
[ "$rc" -eq 0 ] && pass "creation exit 0" || fail "creation exit=$rc : $OUT"
echo "$OUT" | grep -q "Task ID: TASK-074.2" && pass "TASK_ID = TASK-074.2" || fail "TASK_ID incorrect"
[ -f "$b/TODO/TASK-074-t074-2-audit-chatloop.md" ] && pass "fichier sous-tache correct" || fail "fichier sous-tache absent"
has_branch "$b" "T074.2-t074-2-audit-chatloop" && pass "branche T074.2-… creee" || fail "branche sous-tache absente"

# ---------------------------------------------------------
# 12. Options --subtask dans un autre ordre => accepte
# ---------------------------------------------------------
echo "TEST 12: ordre des options libre"
b=$(make_bench)
rc=0; run_sut "$b" --subtask=T074.3 "Titre En Premier Option Avant" FABLE || rc=$?
[ "$rc" -eq 0 ] && pass "--subtask=X avant le titre accepte" || fail "--subtask=X avant le titre refuse : $OUT"
echo "$OUT" | grep -q "Task ID: TASK-074.3" && pass "TASK-074.3 creee" || fail "TASK-074.3 absente"
git -C "$b" checkout -q develop
rc=0; run_sut "$b" "Titre Puis Option" --subtask T074.4 FABLE || rc=$?
[ "$rc" -eq 0 ] && pass "--subtask entre titre et owner accepte" || fail "ordre titre/option/owner refuse : $OUT"
echo "$OUT" | grep -q "Owner: FABLE" && pass "OWNER correctement associe" || fail "OWNER mal associe"

# ---------------------------------------------------------
# 13. Aucune collision fichier/branche
# ---------------------------------------------------------
echo "TEST 13: aucune collision fichier/branche"
b=$(make_bench)
touch "$b/TODO/TASK-1500-seed.md"
rc=0; run_sut "$b" "Premiere du banc" FABLE || rc=$?
[ "$rc" -eq 0 ] && pass "premiere creation ok" || fail "premiere creation ko : $OUT"
git -C "$b" checkout -q develop
rc=0; run_sut "$b" "Seconde du banc" FABLE || rc=$?
[ "$rc" -eq 0 ] && pass "seconde creation ok" || fail "seconde creation ko : $OUT"
{ [ -f "$b/TODO/TASK-1501-premiere-du-banc.md" ] && [ -f "$b/TODO/TASK-1502-seconde-du-banc.md" ]; } \
  && pass "deux identifiants distincts (1501 puis 1502), zero collision" \
  || fail "collision ou reattribution d'identifiant"
git -C "$b" checkout -q develop
rc=0; run_sut "$b" "Audit ChatLoop" OPENCODE --subtask T074.9 || rc=$?
[ "$rc" -eq 0 ] && pass "sous-tache T074.9 creee" || fail "sous-tache T074.9 ko : $OUT"
git -C "$b" checkout -q develop
rc=0; run_sut "$b" "Audit ChatLoop" OPENCODE --subtask T074.9 || rc=$?
[ "$rc" -ne 0 ] && pass "re-creation T074.9 refusee (collision detectee)" || fail "collision sous-tache non detectee"
echo "$OUT" | grep -q "already exists" && pass "message de collision explicite" || fail "message de collision absent"

# ---------------------------------------------------------
# 14. Execution depuis un autre cwd : BASE_DIR fait foi
# ---------------------------------------------------------
echo "TEST 14: autre cwd, BASE_DIR fait foi"
b=$(make_bench)
touch "$b/TODO/TASK-1600-seed.md"
OTHER_CWD=$(mktemp -d "${TMPDIR:-/tmp}/create-task-cwd.XXXXXX")
BENCHES+=("$OTHER_CWD")
rc=0; RUN_CWD="$OTHER_CWD" run_sut "$b" "Depuis ailleurs" FABLE || rc=$?
[ "$rc" -eq 0 ] && pass "creation depuis un autre cwd ok" || fail "creation depuis un autre cwd ko : $OUT"
[ -f "$b/TODO/TASK-1601-depuis-ailleurs.md" ] && pass "TASK file ecrit dans le banc, pas dans le cwd" || fail "TASK file absent du banc"
has_branch "$b" "TASK-1601-depuis-ailleurs" && pass "branche creee dans le depot du banc" || fail "branche absente du depot du banc"
[ ! -d "$OTHER_CWD/TODO" ] && pass "rien n'a ete ecrit dans le cwd d'execution" || fail "le cwd d'execution a ete pollue"

# ---------------------------------------------------------
# 15. (complement brief) option inconnue / --range : erreur explicite
# ---------------------------------------------------------
echo "TEST 15: option inconnue et --range refusees explicitement"
b=$(make_bench)
rc=0; run_sut "$b" "Titre" FABLE --frobnicate || rc=$?
[ "$rc" -ne 0 ] && pass "option inconnue => exit non-zero" || fail "option inconnue acceptee en silence"
echo "$OUT" | grep -q "option inconnue" && pass "message 'option inconnue' explicite" || fail "erreur non explicite"
[ "$(todo_count "$b")" = "0" ] && pass "option inconnue: rien n'est cree" || fail "option inconnue: TASK creee"
rc=0; run_sut "$b" "Titre" FABLE --range=rag || rc=$?
[ "$rc" -ne 0 ] && pass "--range=rag => refus (plages supprimees)" || fail "--range=rag encore accepte"
echo "$OUT" | grep -q "supprimees" && pass "message de suppression des plages explicite" || fail "message de deprecation absent"
{ [ "$(todo_count "$b")" = "0" ] && [ "$(branch_count "$b")" = "1" ]; } \
  && pass "--range: rien n'est cree" || fail "--range: effet de bord detecte"

# ---------------------------------------------------------
# SUMMARY
# ---------------------------------------------------------
echo ""
echo "====================================="
echo "RESULTATS: $PASS_COUNT PASS, $FAIL_COUNT FAIL"
echo "====================================="
if [ "$FAIL_COUNT" -gt 0 ]; then
  echo ""
  echo "Echecs:"
  for f in "${FAILURES[@]}"; do echo "  - $f"; done
  exit 1
fi
exit 0
