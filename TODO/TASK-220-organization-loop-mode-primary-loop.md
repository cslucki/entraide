---
task_id: TASK-220
title: Organization Loop Mode & Primary Loop

status: IN_PROGRESS

owner: CODEUR

contributors:
  - ORCHESTRATOR
  - CODEUR

branch: TASK-220-organization-loop-mode-primary-loop

priority: HIGH

created_at: 2026-06-07 13:38:58 Europe/Paris
updated_at: 2026-06-07 13:41:22 Europe/Paris

labels: []

lock:
  status: LOCKED
  agent: CODEUR
  since: 2026-06-07 13:41:22 Europe/Paris

handoff: true

pr:
  status: NOT_READY
  url: null
---

# Objective

Organization Loop Mode & Primary Loop.

Définir au niveau `Organization` :

1. un mode de fonctionnement des Boucles : mono-boucle ou multi-boucles ;
2. une Boucle principale / Boucle par défaut.

Formulation canonique :

`Boucle principale = Boucle accessible par défaut à tous les membres de l'Organization.`

Ne pas confondre accès automatique, auto-join silencieux et invitation automatique.

Scope final attendu : une seule tâche bornée Organization-scoped. Pas de shell bêta Chat/Flux/Journal/Agents, pas de fichiers, pas de replies, pas de Reverb/Echo/WebSocket, pas de previews YouTube/OpenGraph, pas de dashboards Organization-scoped complets.

---

# Planned Actions

- [ ] Inspecter stockage Organization actuel (`organizations`, ancien `organization_settings`, `loops_enabled`)
- [ ] Définir stratégie DB additive si nécessaire
- [ ] Ajouter mode mono-boucle / multi-boucles au niveau `Organization`
- [ ] Ajouter Boucle principale / par défaut Organization-scoped
- [ ] Adapter `/loops` : mono-boucle redirige vers Boucle principale pour user connecté
- [ ] Adapter `/loops` : multi-boucles affiche la liste des Boucles disponibles
- [ ] Afficher warning `La Boucle par défaut n'est pas définie` si mono-boucle sans Boucle principale
- [ ] Garantir l'accès de la Boucle principale aux membres connectés de l'Organization sans fuite cross-org
- [ ] Ajouter admin minimal pour définir mode + Boucle principale selon l'existant réel
- [ ] Ajouter tests ciblés
- [ ] Vérification VERIFICATOR
- [ ] check-task.sh
- [ ] finalize-task.sh

---
# Progress Log


## 2026-06-07 13:38:58 Europe/Paris

Task created.

Owner:
ORCHESTRATOR

Branch:
TASK-220-organization-loop-mode-primary-loop

Status:
IN_PROGRESS

## 2026-06-07 13:39:15 Europe/Paris

ORCHESTRATOR setup.

Décisions de cadrage :
- `Organization = Tenant`, `Loop != Tenant`, `Public != Global`.
- `organization_settings` a été migré/supprimé dans `2026_06_04_000001_add_settings_to_organizations_table.php`; les réglages existants sont désormais des colonnes directes sur `organizations` (`loops_enabled`, etc.).
- Migration autorisée uniquement si additive, compatible PostgreSQL + SQLite, sans suppression de colonne, sans commande destructive.
- La Boucle principale doit rester Organization-scoped : une Organization ne peut pas pointer vers une boucle d'une autre Organization.
- Si le modèle exige `LoopMember`, CODEUR doit documenter explicitement le choix : accès par règle spéciale, création contrôlée au premier accès, ou backfill/action admin.
- Ne pas implémenter les fonctionnalités hors scope listées dans l'objectif.

Conversation file:
`ai-local/conversations/20260607-13h39-TASK-220-organization-loop-mode-primary-loop.md`

# Handoffs

## 2026-06-07 13:41:22 Europe/Paris

Previous Owner:
ORCHESTRATOR

New Owner:
CODEUR

Status:
IN_PROGRESS

---


# Tests

- [ ] mode mono-boucle redirige `/loops` vers la Boucle principale
- [ ] mode mono-boucle sans Boucle principale affiche le warning attendu
- [ ] mode multi-boucles affiche la liste
- [ ] Boucle principale accessible à un membre de la même Organization
- [ ] cross-Organization bloqué
- [ ] admin peut définir mode + Boucle principale
- [ ] test DB cible `bouclepro_test` vérifié avant toute commande PHPUnit

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
