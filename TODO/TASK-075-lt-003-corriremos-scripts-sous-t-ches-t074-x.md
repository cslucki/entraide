---
task_id: TASK-075
title: LT-003 — corriremos scripts sous-tâches T074.x

status: DONE

contributors:
  - OPENCODE

branch: TASK-075-lt-003-corriremos-scripts-sous-t-ches-t074-x

priority: MEDIUM

created_at: 2026-05-15 02:03:40 Europe/Paris
updated_at: 2026-05-15 02:15:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Corriger `ai/scripts/check-task.sh`, `finalize-task.sh` et `merge-task.sh` pour supporter les sous-tâches de type `T074.1A` (notation pointée). Le script `check-task.sh` ne reconnaissait que le motif `TASK-XXX` dans les noms de branche, ce qui rendait impossible la détection des branches comme `T074.1A-t074-1a-ia-solution-spike-chatloop-assisted-interactions` et des fichiers `TASK-074-t074-1a-ia-....md`.

Contraintes : scope scripts uniquement, ne pas toucher à composer.json / package.json / app Laravel, préserver la compatibilité legacy TASK-XXX.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run shell validation (8 tests)
- [x] dry-run mode added

---
# Progress Log


## 2026-05-15 02:03:40 Europe/Paris

Task created.

Owner:
OPS

Branch:
TASK-075-lt-003-corriremos-scripts-sous-t-ches-t074-x

Status:
IN_PROGRESS

## 2026-05-15 02:05:00 Europe/Paris

### Problem
check-task.sh ne supportait que le pattern `TASK-XXX` dans les noms de branche (grep `TASK-[0-9]{3}`). Les sous-tâches comme `T074.1A` (branche `T074.1A-t074-1a-...`, fichier `TASK-074-t074-1a-...`) n'étaient pas reconnues.

### Changes

**ai/scripts/check-task.sh:**
- Ajout de `resolve_task_file()` : supporte 4 cas
  - Cas 1 : chemin complet `.md` (TODO/xxx.md, TASK-xxx.md)
  - Cas 2 : `T074.1A` → se traduit en `TASK-074-t074-1a-*.md` (subtask shorthand)
  - Cas 3 : `TASK-XXX-*` prefix → find + narrow avec dash si ambigu
  - Cas 4 : nombre brut → `TASK-{num}-*.md` (legacy)
- Ajout de `extract_task_ref()` : extrait `T074.1A` ou `TASK-XXX` depuis le nom de branche
- Ajout du flag `--dry-run` / `-n` : exécute tous les checks sans exit failure
- Fix `set -e` autour du bloc Python YAML (`|| PY_EXIT=$?`)
- Argument parsing multi-flag pour supporter `--dry-run T074.1A`

**ai/scripts/finalize-task.sh:**
- Passage de tous les args (`"$@"`) à check-task.sh au lieu de `"$1"` seulement

**ai/scripts/merge-task.sh:**
- Ajout de `SCRIPTS_DIR`
- Ajout étape 2 : appel à check-task.sh avant merge (gate DONE+UNLOCKED)
- Renumérotation des sections 3→9
- Passage de tous les args (`"$@"`) à check-task.sh

### Resolution logic (resolve_task_file)
| Input | Cherche | Match |
|-------|---------|-------|
| `T074.1A` | `TASK-074-t074-1a*.md` | TASK-074-t074-1a-... |
| `T074.0` | `TASK-074-t074-0*.md` | TASK-074-t074-0-... |
| `TASK-074-t074-1a` | `TASK-074-t074-1a*.md` | unique match |
| `TASK-058` | `TASK-058*.md` → `TASK-058-*.md` | TASK-058-task-058-... |
| `TASK-074` | `TASK-074*.md` (2 files) → `TASK-074-*.md` (0) | AMBIGUOUS (error) |
| `TODO/*.md` | direct file check | as-is |
| (no arg) | extract from branch `T074.1A-...` → `T074.1A` | same as above |

## 2026-05-15 02:15:00 Europe/Paris

### Finalization

- Status DONE, lock UNLOCKED
- TASK file fully documented
- `bash -n` syntax verified on all 3 scripts
- 3 validation tests re-run (T074.1A, dry-run T074.0, dry-run TASK-074)
- Commit + push: see git log

# Handoffs

# Tests

- [x] shell syntax validation (bash -n)
- [x] T074.1A resolution test
- [x] T074.0 resolution test
- [x] legacy TASK-058 resolution test
- [x] ambiguous TASK-074 error test
- [x] not-found TASK-999 error test
- [x] branch-based detection test
- [x] dry-run mode test
- [x] finalize-task.sh propagation test
- [x] merge-task.sh check gate test

---

# Test Results

All 8 shell tests pass. Dry-run mode verified.

Test commands:
```
bash ai/scripts/check-task.sh --dry-run T074.1A     # PASS
bash ai/scripts/check-task.sh --dry-run T074.0       # PASS (MERGED + DRY-RUN)
bash ai/scripts/check-task.sh --dry-run TASK-074-t074-1a  # PASS
bash ai/scripts/check-task.sh --dry-run TASK-058     # PASS (legacy)
bash ai/scripts/check-task.sh --dry-run "TODO/TASK-074-t074-1a-..."  # PASS
bash ai/scripts/check-task.sh --dry-run TASK-074     # PASS (ambiguous error)
bash ai/scripts/check-task.sh --dry-run TASK-999     # PASS (not found)
bash ai/scripts/check-task.sh --dry-run              # PASS (branch detection)
```

---

# Review Notes

- `resolve_task_file()` handles 4 resolution cases
- `extract_task_ref()` prioritizes T074.1A pattern over TASK-XXX
- Legacy `TASK-073` correctly narrows via `TASK-073-*.md` to `TASK-073-STATUS.md`
- `T074.0` correctly maps to `TASK-074-t074-0-*.md`
- `set -e` mitigated via `|| PY_EXIT=$?` pattern in Python block
- Scripts unchanged: composer.json, package.json, Laravel app