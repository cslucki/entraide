---
task_id: TASK-170
title: Community Model Removal Feasibility & Patch Plan

status: SUPERSEDED

owner: SUPERVISOR

contributors: []

branch: TASK-170-community-model-removal-feasibility-patch-plan

priority: MEDIUM

created_at: 2026-05-30 07:33:17 Europe/Paris
updated_at: 2026-06-01 11:35:00 Europe/Paris

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

Describe the objective.

---

# Planned Actions

- [ ] inspect architecture
- [ ] inspect impacted files
- [ ] implement changes
- [ ] run tests
- [ ] validate UI

---
# Progress Log


## 2026-05-30 07:33:17 Europe/Paris

Task created.

## 2026-05-30 HARD STOP — 13 tests typed properties bloquent

Architecture Community extends Model stable. Organization extends Community conservé.
Toute tentative d'inversion de l'héritage → 221 test failures.
Root cause: 19 typed properties `private Community $community` dans 13 fichiers de test.
PHP typed properties rejettent Organization quand Community n'est plus superclasse.

Task created.

Owner:
SUPERVISOR

Branch:
TASK-170-community-model-removal-feasibility-patch-plan

Status:
IN_PROGRESS

## 2026-06-01 11:35:00 Europe/Paris

Administrative alignment (TASK-189): SUPERSEDED.
All blockers resolved by TASK-171 (typed properties), TASK-173 (Community.php deleted), TASK-174 (aliases/middleware), TASK-188 (residue audit confirmed NO_BLOCKING_RESIDUE).

# Handoffs

# Tests

- [x] feature tests — 221 failures si inversion héritage (13 fichiers, 19 typed properties)
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [ ] tenant validation

---

# Test Results

Pending.

---

# Review Notes

SUPERSEDED by TASK-171 (typed property fix) + TASK-173 (Community.php deletion) + TASK-174 (aliases/middleware removal) + TASK-188 (final residue audit). All blockers resolved.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`

Note: TASK-170 was not merged as an implementation branch. This TASK file was recovered into develop as the canonical blocked-task record after the failed Community model removal feasibility attempt.