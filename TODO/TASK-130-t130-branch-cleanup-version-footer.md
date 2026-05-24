---
task_id: TASK-130
title: t130-branch-cleanup-version-footer

status: IN_PROGRESS

owner: CODEX

contributors: []

branch: TASK-130-t130-branch-cleanup-version-footer

priority: MEDIUM

created_at: 2026-05-24 03:31:15 Europe/Paris
updated_at: 2026-05-24 03:35:00 Europe/Paris

labels:
  - cleanup
  - git
  - footer
  - housekeeping

lock:
  status: LOCKED
  agent: CODEX
  since: 2026-05-24 03:31:15 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Remettre le cockpit Git et la version footer au carré après la série T122-T129.

Deux axes :
1. **Branch cleanup** — supprimer les branches distantes clairement mergées et safe.
2. **Version footer** — mettre en place ou corriger le footer version dans l'UI (cible : v0.130-alpha).

---

# Hors scope

- Pas de tenant / BelongsToTenantScope
- Pas de migration DB / community_id → organization_id
- Pas de renommage global Community → Organization
- Pas de correction SQLite batch
- Pas de refonte UI
- Pas de main / PROD

---

# Planned Actions

## Phase A — Branch cleanup

- [ ] lister les branches distantes mergées dans develop (`git branch -r --merged develop`)
- [ ] identifier les branches safe à supprimer (TASK-054 → TASK-128 mergées confirmées)
- [ ] ne pas supprimer : T126 (absorption T127 à documenter), TASK-129 (finalize commit hors develop), branches non-mergées, branches jules/*, release-*, ALPHA-*
- [ ] supprimer les branches safe côté origin
- [ ] vérifier git branch -r après nettoyage

## Phase B — Version footer

- [ ] identifier où le footer version est défini dans l'UI (Blade layouts / config)
- [ ] mettre à jour la version à v0.130-alpha
- [ ] vérifier le rendu UI (laravel-boost browser-logs ou screenshot)

## Phase C — Finalisation

- [ ] mettre à jour TASK-130 (progress log, tests, review notes)
- [ ] commit + push
- [ ] finalize-task.sh TASK-130
- [ ] merge sur develop
- [ ] marquer MERGED

---

# Progress Log

## 2026-05-24 03:31:15 Europe/Paris

Task créée. Branch TASK-130-t130-branch-cleanup-version-footer depuis develop propre (post-merge T129, 9cd3d47).

### Audit branches initial (pré-T130)

**Branches mergées dans develop (supprimables) :**
- TASK-054 à TASK-122 (série complète, toutes mergées)
- T074.2 à T074.9 (sous-tâches mergées)
- T077.3
- TASK-126, TASK-127, TASK-128
- LT-001-admin-send-password-reset-link
- chore/remove-local-review-task-script

**À ne pas supprimer (non-mergées ou précaution) :**
- TASK-129 — finalize commit (cdd5381) hors develop, safe à terme
- T074.1, T074.1A — statut à vérifier
- TASK-073E, TASK-075, TASK-076 — non-mergées, vérifier abandon
- TASK-092 à TASK-097 — sous-tâches T075 non-mergées
- TASK-106 — admin IA non-mergée
- jules/* — branches externes AI
- release-t073-prod-backport — release branch
- ALPHA-SETUP-01 — inconnu, à investiguer

---

# Tests

- [ ] git status propre après cleanup
- [ ] footer version visible dans UI (hors scope CI)
- [ ] aucune branche live supprimée par erreur

---

# Test Results

Pending.

---

# Review Notes

Pending.
