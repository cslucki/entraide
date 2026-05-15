---
task_id: TASK-076
title: LT-004 Tooling support for explicit subtask creation

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-076-lt-004-tooling-support-for-explicit-subtask-creation

priority: MEDIUM

created_at: 2026-05-15 02:46:51 Europe/Paris
updated_at: 2026-05-15 02:46:51 Europe/Paris

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

Ajouter le flag optionnel `--subtask T074.1A` à `ai/scripts/create-task.sh` pour créer des sous-tâches de type T074.x avec la convention de nommage correcte (fichier `TASK-074-t074-1a-*.md`, branche `T074.1A-t074-1a-*`). Le mode standard (auto-incrément TASK-XXX) reste strictement inchangé.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] apply patch from LT-003
- [x] validate syntax + logic
- [x] commit and push

---
# Progress Log


## 2026-05-15 02:46:51 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-076-lt-004-tooling-support-for-explicit-subtask-creation

Status:
IN_PROGRESS

## 2026-05-15 02:48:00 Europe/Paris

### Patch applied + validated

- Patch `create-task.sh --subtask` appliqué depuis LT-003
- Mode subtask supporté :
  - `--subtask T074.2` / `--subtask=T074.2`
  - Mapping : T074.1A → fichier `TASK-074-t074-1a-*.md`, branche `T074.1A-t074-1a-*`
  - Validation format regex `^T([0-9]+)\.([A-Za-z0-9]+)$`
  - Refus si fichier existe déjà
  - Refus si branche existe déjà (local + remote)
- Mode standard strictement inchangé
- 6 tests unitaires shell passés
- Syntaxe bash OK

# Handoffs

# Tests

- [x] shell syntax (bash -n)
- [x] standard mode regression (auto-incrément TASK-076)
- [x] --subtask T074.2 → fichier/branche corrects
- [x] --subtask=T074.2 → parsing correct
- [x] --subtask T074.1A → refus fichier existant
- [x] --subtask INVALID → refus format
- [x] --subtask T074 → refus (pas de dot)

---

# Test Results

### Unit tests (6/6 pass)

| # | Test | Résultat |
|---|------|----------|
| 1 | Standard "Test task" TEST | TASK-076, fichier TASK-076-test-task.md, branche standard |
| 2 | --subtask T074.2 "Some chatloop feature" | TASK_ID=TASK-074.2, file TASK-074-t074-2-..., branch T074.2-t074-2-... |
| 3 | --subtask T074.1A "IA Solution Spike" | TASK_ID=TASK-074.1A, file TASK-074-t074-1a-..., branch T074.1A-t074-1a-... |
| 4 | INVALID (no T###.##) | rejected |
| 5 | T074 (no dot suffix) | rejected |
| 6 | T074.1A file+branch existence | rejected (file AND branch exist) |

### Syntax
- `bash -n ai/scripts/create-task.sh` → OK

---

# Review Notes

- Patch issu du travail LT-003 (re-créé sur branche propre)
- Mode subtask optionnel, ne casse pas le mode standard
- Fichier modifié : `ai/scripts/create-task.sh` uniquement
- Aucun asset commité