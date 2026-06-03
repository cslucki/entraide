---
task_id: TASK-207
title: Rename supervisor orchestration role to codeur

status: IN_PROGRESS

owner: ORCHESTRATOR

contributors: []

branch: TASK-207-rename-supervisor-orchestration-role-to-codeur

priority: MEDIUM

created_at: 2026-06-03 21:33:47 Europe/Paris
updated_at: 2026-06-03 21:33:47 Europe/Paris

labels: []

lock:
  status: LOCKED
  agent: ORCHESTRATOR
  since: 2026-06-03 21:33:47 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Rename the orchestration role "supervisor" to "codeur" across `ai-local` and main repo documentation to clarify agent responsibilities:
- ORCHESTRATOR: coordinates, plans, never codes directly
- CODEUR (ex-SUPERVISOR): executes bounded work, code, tests, reports
- VERIFICATOR: verifies before/after, does not correct unless explicitly requested

---

# Planned Actions

## Étape 1 — Audit lexical et chemins ✅
- [x] Vérifier l'état Git des deux dépôts (principal + ai-local)
- [x] Identifier la structure des dossiers dans ai-local
- [x] Chercher toutes les occurrences de supervisor/Supervisor/SUPERVISOR

## Étape 2 — Renommage des dossiers dans `ai-local` ✅
- [x] Renommer `ai-local/supervisor/` → `ai-local/codeur/` (git mv)
- [x] Renommer sous-dossiers `message-from-supervisor` → `message-from-codeur`
- [x] Renommer sous-dossiers `message-to-supervisor` → `message-to-codeur`
- [x] Conserver `report-from-orchestrator` (rapport DE orchestrator)
- [x] Conserver `report-to-orchestrator` (rapport VERS orchestrator)

## Étape 3 — Renommage des fichiers actifs ✅
- [x] Renommer `supervisor-protocol.md` → `codeur-protocol.md`

## Étape 4 — Remplacement lexical borné (fichiers actifs) ✅
- [x] Modifier `ai-local/README.md` (structure arborescence)
- [x] Modifier `ai-local/orchestrator/README.md`
- [x] Modifier `ai-local/orchestrator/skills/codeur-protocol.md`
- [x] Modifier `ai-local/orchestrator/skills/README.md`
- [x] Modifier `ai-local/codeur/report-from-orchestrator/README.md`
- [x] Modifier `ai-local/codeur/report-to-orchestrator/README.md`

## Étape 5 — Remplacement lexical dans repo principal ✅
- [x] Modifier `AGENTS.md` (Orchestrator-Supervisor Contract Rules → Orchestrator-Codeur Contract Rules)
- [x] Conserver occurrences historiques dans rapports

## Étape 6 — Clarification sémantique ✅
- [x] Ajouter note explicative dans `ai-local/` sur ancien/nouveau nom

## Étape 7 — Vérification anti-erreur
- [ ] Relire les chemins après renommage
- [ ] Classifier occurrences restantes (historiques vs actives vs ambiguës)
- [ ] Confirmer aucun code applicatif Laravel modifié
- [ ] Confirmer aucune commande DB destructive

## Étape 8 — Commit
- [ ] Vérifier git diff
- [ ] Commit: docs(orchestration): rename supervisor role to codeur

---
# Progress Log

## 2026-06-03 21:33:47 Europe/Paris

Task created.

Owner:
ORCHESTRATOR

Branch:
TASK-207-rename-supervisor-orchestration-role-to-codeur

Status:
IN_PROGRESS

## 2026-06-03 21:45:00 Europe/Paris

Etapes 1-6 completes :

- Audit lexical effectue : 100+ occurrences identifiees
- Dossiers renommes : supervisor → codeur, message-from-supervisor → message-from-codeur, message-to-supervisor → message-to-codeur
- Fichier renomme : supervisor-protocol.md → codeur-protocol.md
- Fichiers actifs modifies : ai-local/README.md, ai-local/orchestrator/README.md, codeur-protocol.md, skills/README.md
- AGENTS.md modifie : section "Orchestrator-Supervisor Contract Rules" → "Orchestrator-Codeur Contract Rules"
- Note explicative creee : ai-local/RENOMMAGE_SUPERVISOR_VERS_CODEUR.md

Decision : report-from-orchestrator et report-to-orchestrator conserves car ils decrivent le sens du flux, pas le nom de l'agent.

Fichiers non modifies intentionnellement :
- ai-local/orchestrator/working/current-focus.md (verifie - aucune occurrence)
- ai-local/orchestrator/working/current-run.md (verifie - aucune occurrence)
- Rapports historiques dans _Cyril/ (preserves pour integrite)

Prochaine etape : verification anti-erreur puis commit.

# Handoffs

# Tests

- [ ] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [ ] tenant validation

---

# Test Results

Pending.

---

# Review Notes

Pending.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`