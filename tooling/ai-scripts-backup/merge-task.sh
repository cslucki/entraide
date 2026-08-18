#!/bin/bash

# =========================================================
# MERGE TASK SCRIPT — WORKTREE-LOCAL COPY (TASK-1215)
# =========================================================
# Reecriture PR-native (TASK-1215). `develop` est protege cote GitHub par
# une regle serveur ("Changes must be made through a pull request") : tout
# `git push origin develop` est rejete avec GH013, quel que soit l'etat des
# CI ou du contenu. La version precedente de ce script (merge local +
# push develop) ne peut donc plus jamais aboutir — ce n'est pas un cas
# d'erreur a corriger, c'est un chemin de code a supprimer.
#
# Ce script ne merge plus rien lui-meme : il verifie que la PR associee a
# la branche courante est prete (etat, base, HEAD, mergeable, CI), puis
# demande a GitHub de fusionner via `gh pr merge --merge`. La preuve de
# merge est GitHub (pr.state == MERGED), jamais un `git push origin develop`
# local.
#
# Strategie canonique : MERGE COMMIT. Jamais squash, jamais rebase (voir
# TASK-1215 Progress Log pour la justification : preserver l'historique des
# commits TASK, rester proche de l'ancien `git merge --no-ff`).
#
# INTERDIT dans ce script (ne jamais reintroduire) :
#   - git push origin develop
#   - git checkout develop (checkout de la branche LOCALE pour y merger)
#   - tout contournement/desactivation de la protection de branche
#   - fallback vers test.laravel
#   - squash / rebase par defaut
#
# Statut TASK apres merge — decision TASK-1215 (option A auditee) :
# `check-task.sh` exige `status: DONE` pour laisser passer son gate ; ce
# champ reste DONE, y compris apres le merge reel. Il n'existe PAS de
# second etat "MERGED" ecrit dans le TASK file : une fois la PR fusionnee,
# GitHub (pr.state == MERGED, pr.mergedAt, pr.mergeCommit) est l'autorite
# du fait de merge. Ecrire "MERGED" dans le fichier necessiterait un commit
# sur une branche deja mergee, donc une seconde PR rien que pour ce label —
# rejete comme cout disproportionne (voir A3 dans TASK-1215). Ce script
# n'ecrit donc jamais dans le TASK file apres merge ; il rapporte le fait
# de merge en sortie standard, a charge de l'appelant de le consigner dans
# le Progress Log AVANT le merge (etat "pret a fusionner"), pas apres.
#
# Usage:
#   ./merge-task.sh [TASK_ID] [--dry-run|-n] [--yes|-y]
#
#   --dry-run   Execute toutes les verifications (check-task, PR, base,
#               HEAD, mergeable, CI) sans jamais appeler `gh pr ready` ni
#               `gh pr merge`. Rien n'est modifie sur GitHub ni en local.
#   --yes       Saute la confirmation interactive avant le merge reel (pour
#               usage scripte/agent). Sans ce flag, une confirmation
#               `read -p` est requise. N'affecte PAS les gates de securite
#               (CI rouge/absente reste bloquant meme avec --yes).
#
# Ce fichier est ecrit pour etre "sourceable" a des fins de tests (voir
# ai/scripts/tests/merge-task-smoke.sh) : toute la logique vit dans des
# fonctions, l'execution reelle n'a lieu que si le script est appele
# directement (voir le garde tout en bas).
# =========================================================

set -e

BASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPTS_DIR="$BASE_DIR/ai/scripts"

DRY_RUN=false
ASSUME_YES=false
PR_TARGET_BASE="develop"

# ---------------------------------------------------------
# parse_args
# ---------------------------------------------------------
parse_args() {
  CHECK_TASK_ARGS=()
  for arg in "$@"; do
    case "$arg" in
      --dry-run|-n) DRY_RUN=true ;;
      --yes|-y) ASSUME_YES=true ;;
      *) CHECK_TASK_ARGS+=("$arg") ;;
    esac
  done
}

# ---------------------------------------------------------
# require_tools — gh et jq sont indispensables a ce script (pas optionnels
# comme dans l'ancienne version : sans gh, aucun merge n'est possible ici).
# ---------------------------------------------------------
require_tools() {
  local missing=()
  command -v gh &> /dev/null || missing+=("gh")
  command -v jq &> /dev/null || missing+=("jq")

  if [ ${#missing[@]} -gt 0 ]; then
    echo "ERROR: outils requis absents: ${missing[*]}"
    echo "Ce script PR-native ne peut pas verifier/merger la PR sans eux."
    exit 1
  fi
}

# ---------------------------------------------------------
# guard_branch — jamais sur main/develop
# ---------------------------------------------------------
guard_branch() {
  CURRENT_BRANCH=$(git branch --show-current)

  if [ "$CURRENT_BRANCH" = "main" ]; then
    echo "ERROR: Cannot merge from main branch."
    exit 1
  fi

  if [ "$CURRENT_BRANCH" = "develop" ]; then
    echo "ERROR: Already on develop branch."
    exit 1
  fi

  echo "Source branch: $CURRENT_BRANCH"
  echo "Target branch: $PR_TARGET_BASE (via PR GitHub, jamais de push direct)"
  echo ""
}

# ---------------------------------------------------------
# run_check_task — gate inchange : DONE + UNLOCKED (TASK-1215 ne touche pas
# check-task.sh, cf. audit A1/A3 : le statut DONE reste la seule verite
# versionnee, MERGED est un fait GitHub, jamais un statut de fichier).
# ---------------------------------------------------------
run_check_task() {
  echo "Running pre-merge task check..."
  echo ""

  if ! bash "$SCRIPTS_DIR/check-task.sh" "${CHECK_TASK_ARGS[@]}"; then
    echo ""
    echo "====================================="
    echo "TASK CHECK FAILED"
    echo "====================================="
    echo ""
    echo "Task must be DONE and UNLOCKED before merging."
    echo "Resolve issues and re-run merge-task.sh."
    exit 1
  fi

  echo ""
  echo "Task check passed."
  echo ""
}

# ---------------------------------------------------------
# guard_clean_status
# ---------------------------------------------------------
guard_clean_status() {
  local porcelain
  porcelain=$(git status --porcelain)

  if [ -n "$porcelain" ]; then
    echo "ERROR: Uncommitted changes detected."
    echo ""
    git status --short
    echo ""
    echo "Commit or stash changes before merging."
    exit 1
  fi

  echo "Git status: CLEAN"
  echo ""
}

# ---------------------------------------------------------
# discover_pr — trouve la PR de CURRENT_BRANCH explicitement (jamais la PR
# "courante" implicite de gh, pour rester correct meme source/teste hors
# contexte interactif).
# ---------------------------------------------------------
discover_pr() {
  echo "Locating PR for branch $CURRENT_BRANCH..."
  echo ""

  local pr_json
  if ! pr_json=$(gh pr view "$CURRENT_BRANCH" --json number,state,isDraft,mergeable,headRefOid,baseRefName,url 2>&1); then
    echo "ERROR: no PR found for branch $CURRENT_BRANCH."
    echo "$pr_json"
    exit 1
  fi

  PR_NUMBER=$(printf '%s' "$pr_json" | jq -r '.number')
  PR_STATE=$(printf '%s' "$pr_json" | jq -r '.state')
  PR_DRAFT=$(printf '%s' "$pr_json" | jq -r '.isDraft')
  PR_MERGEABLE=$(printf '%s' "$pr_json" | jq -r '.mergeable')
  PR_HEAD=$(printf '%s' "$pr_json" | jq -r '.headRefOid')
  PR_BASE=$(printf '%s' "$pr_json" | jq -r '.baseRefName')
  PR_URL=$(printf '%s' "$pr_json" | jq -r '.url')

  echo "  PR #$PR_NUMBER ($PR_URL)"
  echo "  state=$PR_STATE draft=$PR_DRAFT mergeable=$PR_MERGEABLE base=$PR_BASE"
  echo "  head=$PR_HEAD"
  echo ""
}

# ---------------------------------------------------------
# guard_already_merged — idempotence : si la PR est deja MERGED, ne pas
# echouer, juste confirmer l'etat et sortir proprement (fetch + parking en
# mode reel, rien en dry-run).
# ---------------------------------------------------------
guard_already_merged() {
  if [ "$PR_STATE" = "MERGED" ]; then
    echo "PR #$PR_NUMBER est deja MERGED. Rien a faire (idempotent)."
    if ! $DRY_RUN; then
      git fetch origin
      park_worktree
    fi
    print_summary_already_merged
    exit 0
  fi
}

# ---------------------------------------------------------
# guard_pr_state — doit etre OPEN (ni CLOSED sans merge, ni draft-only sans
# etre finalement OPEN — draft est aussi un etat OPEN cote GitHub).
# ---------------------------------------------------------
guard_pr_state() {
  if [ "$PR_STATE" != "OPEN" ]; then
    echo "ERROR: PR #$PR_NUMBER state is $PR_STATE (expected OPEN)."
    exit 1
  fi
}

# ---------------------------------------------------------
# guard_pr_base
# ---------------------------------------------------------
guard_pr_base() {
  if [ "$PR_BASE" != "$PR_TARGET_BASE" ]; then
    echo "ERROR: PR #$PR_NUMBER base is '$PR_BASE', expected '$PR_TARGET_BASE'."
    exit 1
  fi
}

# ---------------------------------------------------------
# guard_head_match — le HEAD de la PR doit etre EXACTEMENT le HEAD local :
# sinon on merge/valide un commit qui n'est pas celui teste/relu.
# ---------------------------------------------------------
guard_head_match() {
  local local_head
  local_head=$(git rev-parse HEAD)

  if [ "$PR_HEAD" != "$local_head" ]; then
    echo "ERROR: PR head ($PR_HEAD) != local HEAD ($local_head)."
    echo "Pousser la branche a jour avant de merger:"
    echo "  git push origin $CURRENT_BRANCH"
    exit 1
  fi
}

# ---------------------------------------------------------
# guard_pr_mergeable
# ---------------------------------------------------------
guard_pr_mergeable() {
  if [ "$PR_MERGEABLE" != "MERGEABLE" ]; then
    echo "ERROR: PR #$PR_NUMBER mergeable=$PR_MERGEABLE (expected MERGEABLE)."
    echo "GitHub calcule cet etat de facon asynchrone : reessayer dans quelques secondes si besoin."
    exit 1
  fi
}

# ---------------------------------------------------------
# check_ci_gates — logique inchangee depuis l'ancien script (TASK-1126),
# juste parametree sur le HEAD de la PR au lieu du HEAD local (les deux
# sont deja verifies identiques par guard_head_match).
# ---------------------------------------------------------
check_ci_gates() {
  local branch="$1" sha="$2"

  echo "Verifying GitHub gates on $sha..."
  echo ""

  local gates_json
  gates_json=$(gh run list --branch "$branch" --commit "$sha" \
    --json name,status,conclusion 2>/dev/null || echo "[]")

  local verdict
  verdict=$(printf '%s' "$gates_json" | python3 -c '
import json, sys

try:
    runs = json.load(sys.stdin)
except Exception:
    print("UNKNOWN|CI illisible")
    sys.exit()

attendus = {"PostgreSQL CI", "SQLite CI"}
vus = {}
for r in runs:
    nom = r.get("name")
    if nom in attendus and nom not in vus:
        vus[nom] = (r.get("status"), r.get("conclusion"))

manquants = sorted(attendus - set(vus))
if manquants:
    print("UNKNOWN|gate absent pour ce commit: " + ", ".join(manquants))
    sys.exit()

rouges = [n for n, (s, c) in vus.items() if c != "success"]
encours = [n for n, (s, c) in vus.items() if s != "completed"]

if encours:
    print("PENDING|encore en cours: " + ", ".join(sorted(encours)))
elif rouges:
    print("RED|gate non vert: " + ", ".join(sorted(rouges)))
else:
    print("GREEN|les deux gates sont verts")
')

  GATES_STATE="${verdict%%|*}"
  GATES_MSG="${verdict#*|}"

  case "$GATES_STATE" in
    GREEN)
      echo "  GitHub gates: GREEN ($GATES_MSG)"
      echo ""
      ;;
    RED|PENDING)
      echo "ERROR: $GATES_MSG"
      echo ""
      echo "  Commit: $sha"
      echo "  Les deux gates doivent etre verts sur CE commit avant merge."
      echo "  Voir: gh run list --branch $branch"
      exit 1
      ;;
    *)
      echo "  WARN: $GATES_MSG"
      echo "  Impossible de confirmer les gates automatiquement."
      if $ASSUME_YES; then
        echo "ERROR: etat CI inconnu et --yes fourni ; refus de deviner en mode non-interactif."
        exit 1
      fi
      if $DRY_RUN; then
        echo "  (dry-run: une confirmation interactive serait demandee ici)"
      else
        read -p "  Continuer quand meme ? (y/n): " GATES_OVERRIDE
        if [ "$GATES_OVERRIDE" != "y" ]; then
          echo "Merge cancelled."
          exit 1
        fi
      fi
      echo ""
      ;;
  esac
}

# ---------------------------------------------------------
# confirm_merge — saute si --yes ; jamais saute en se basant sur les gates
# (les gates restent bloquants independamment de cette confirmation).
# ---------------------------------------------------------
confirm_merge() {
  if $ASSUME_YES; then
    return 0
  fi

  read -p "Merge PR #$PR_NUMBER ($CURRENT_BRANCH -> $PR_TARGET_BASE) via 'gh pr merge --merge' ? (y/n): " CONFIRM
  if [ "$CONFIRM" != "y" ]; then
    echo ""
    echo "Merge cancelled."
    exit 0
  fi
}

# ---------------------------------------------------------
# ready_pr_if_draft
# ---------------------------------------------------------
ready_pr_if_draft() {
  if [ "$PR_DRAFT" = "true" ]; then
    echo "PR #$PR_NUMBER est en draft : gh pr ready..."
    gh pr ready "$PR_NUMBER"
    echo ""
  fi
}

# ---------------------------------------------------------
# merge_pr — le seul point du script qui modifie l'etat de la PR. Strategie
# fixe : --merge (merge commit). Jamais --squash, jamais --rebase.
# ---------------------------------------------------------
merge_pr() {
  echo "Merging PR #$PR_NUMBER via GitHub (merge commit)..."
  echo ""

  if ! gh pr merge "$PR_NUMBER" --merge; then
    echo ""
    echo "ERROR: gh pr merge a echoue."
    echo "Aucun etat git local n'a ete modifie : ce script ne bascule jamais"
    echo "vers un merge local + push origin develop en repli."
    exit 1
  fi

  echo ""
  echo "Merge PR demande avec succes."
  echo ""
}

# ---------------------------------------------------------
# fetch_and_verify_merged
# ---------------------------------------------------------
fetch_and_verify_merged() {
  echo "Fetching origin..."
  git fetch origin
  echo ""

  local merged_json state merge_commit
  merged_json=$(gh pr view "$PR_NUMBER" --json state,mergedAt,mergeCommit)
  state=$(printf '%s' "$merged_json" | jq -r '.state')
  merge_commit=$(printf '%s' "$merged_json" | jq -r '.mergeCommit.oid // empty')

  if [ "$state" != "MERGED" ]; then
    echo "ERROR: PR #$PR_NUMBER state is '$state' apres gh pr merge (attendu MERGED)."
    exit 1
  fi

  if [ -z "$merge_commit" ]; then
    echo "ERROR: PR #$PR_NUMBER MERGED mais aucun merge commit trouve."
    exit 1
  fi

  if ! git merge-base --is-ancestor "$PR_HEAD" "origin/$PR_TARGET_BASE"; then
    echo "ERROR: origin/$PR_TARGET_BASE ne contient pas le HEAD de la PR ($PR_HEAD)."
    exit 1
  fi

  MERGE_COMMIT="$merge_commit"
  FINAL_DEVELOP=$(git rev-parse "origin/$PR_TARGET_BASE")

  echo "  PR #$PR_NUMBER: MERGED"
  echo "  merge commit: $MERGE_COMMIT"
  echo "  origin/$PR_TARGET_BASE: $FINAL_DEVELOP"
  echo ""
}

# ---------------------------------------------------------
# park_worktree — JAMAIS `git checkout develop` (branche locale). Toujours
# un detached HEAD exact sur origin/develop : evite aussi le conflit "git
# refuse de checkout la meme branche deux fois" entre worktrees, qui etait
# une source d'echec de l'ancien script.
# ---------------------------------------------------------
park_worktree() {
  echo "Parking worktree: detached HEAD on origin/$PR_TARGET_BASE..."
  git checkout "origin/$PR_TARGET_BASE" --detach
  echo ""
  git status --short
  echo ""
}

# ---------------------------------------------------------
# print_dry_run_summary
# ---------------------------------------------------------
print_dry_run_summary() {
  echo "====================================="
  echo "DRY-RUN — toutes les verifications sont passees."
  echo "====================================="
  echo ""
  echo "Aucune action GitHub effectuee (ni gh pr ready, ni gh pr merge)."
  echo "Relancer sans --dry-run (et avec --yes pour un usage non-interactif)"
  echo "pour merger reellement PR #$PR_NUMBER."
  echo ""
}

# ---------------------------------------------------------
# print_summary
# ---------------------------------------------------------
print_summary() {
  echo "====================================="
  echo "MERGE COMPLETE"
  echo "====================================="
  echo ""
  echo "PR #$PR_NUMBER MERGED (merge commit)."
  echo "Merge commit: $MERGE_COMMIT"
  echo "origin/$PR_TARGET_BASE: $FINAL_DEVELOP"
  echo ""
  echo "Worktree: detached HEAD sur origin/$PR_TARGET_BASE."
  echo ""
  echo "Reminders:"
  echo "  - Le TASK file garde status: DONE (pas de reecriture MERGED, cf."
  echo "    en-tete de ce script / TASK-1215)."
  echo "  - Supprimer la branche distante si souhaite:"
  echo "    git push origin --delete $CURRENT_BRANCH"
  echo "  - Supprimer la branche locale:"
  echo "    git branch -d $CURRENT_BRANCH"
  echo ""
}

print_summary_already_merged() {
  echo "====================================="
  echo "ALREADY MERGED"
  echo "====================================="
  echo ""
  echo "PR #$PR_NUMBER etait deja MERGED avant l'execution de ce script."
  echo ""
}

# ---------------------------------------------------------
# main
# ---------------------------------------------------------
main() {
  parse_args "$@"

  echo ""
  echo "====================================="
  echo "MERGE TASK (PR-native)"
  echo "====================================="
  echo ""

  require_tools
  guard_branch
  run_check_task
  guard_clean_status
  discover_pr
  guard_already_merged
  guard_pr_state
  guard_pr_base
  guard_head_match
  guard_pr_mergeable
  check_ci_gates "$CURRENT_BRANCH" "$PR_HEAD"

  if $DRY_RUN; then
    print_dry_run_summary
    exit 0
  fi

  confirm_merge
  ready_pr_if_draft
  merge_pr
  fetch_and_verify_merged
  park_worktree
  print_summary
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
  main "$@"
fi
