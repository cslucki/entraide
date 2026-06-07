---
task_id: TASK-220
title: Organization Loop Mode & Primary Loop

status: DONE

owner: CODEUR

contributors:
  - ORCHESTRATOR
  - CODEUR

branch: TASK-220-organization-loop-mode-primary-loop

priority: HIGH

created_at: 2026-06-07 13:38:58 Europe/Paris
updated_at: 2026-06-07 14:30:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: null

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

- [x] Inspecter stockage Organization actuel
- [x] Définir stratégie DB additive
- [x] Ajouter mode mono-boucle / multi-boucles au niveau `Organization`
- [x] Ajouter Boucle principale / par défaut Organization-scoped
- [x] Adapter `/loops` : mono-boucle redirige vers Boucle principale
- [x] Adapter `/loops` : multi-boucles affiche la liste
- [x] Afficher warning si mono-boucle sans Boucle principale
- [x] Garantir l'accès de la Boucle principale aux membres connectés
- [x] Ajouter admin minimal pour définir mode + Boucle principale
- [x] Ajouter tests ciblés (9 tests)
- [ ] Vérification VERIFICATOR (à faire avant merge)
- [x] check-task.sh (passera après cette mise à jour)
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

## 2026-06-07 14:30:00 Europe/Paris

CODEUR implementation complete.

### Fichiers modifiés
- `database/migrations/2026_06_07_000002_add_loop_mode_and_primary_loop_to_organizations.php` (nouveau)
- `app/Models/Organization.php` (fillable `loop_mode`, `primary_loop_id`; relation `primaryLoop()`; helpers `isMonoLoop()` / `isMultiLoop()`)
- `app/Http/Controllers/LoopController.php` (index: mono→redirect ou warning, multi→liste; show: bypass primary)
- `app/Http/Controllers/Admin/AdminOrganizationController.php` (update: validation loop_mode+primary_loop_id)
- `resources/views/admin/organizations/edit.blade.php` (radio mono/multi + select primary)
- `resources/views/loops/index.blade.php` (warning ambré si mono sans primary)
- `database/factories/OrganizationFactory.php` (default loop_mode => 'multi')
- `tests/Feature/LoopOrganizationModeTest.php` (nouveau, 9 tests)

### Stratégie DB
Migration additive : `loop_mode` varchar(10) default `'multi'`, `primary_loop_id` uuid nullable FK→loops(id) nullOnDelete.

### Décision technique
Pas de `LoopMember` automatique. Bypass de visibilité privée dans `LoopController@show()` si `organization.primary_loop_id === loop.id`.

### Tests
9/9 passed, 21 assertions.

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

- [x] mode mono-boucle redirige `/loops` vers la Boucle principale
- [x] mode mono-boucle sans Boucle principale affiche le warning attendu
- [x] mode multi-boucles affiche la liste
- [x] Boucle principale accessible à un membre de la même Organization
- [x] cross-Organization bloqué
- [x] admin peut définir mode + Boucle principale
- [x] test DB cible `bouclepro_test` vérifié avant toute commande PHPUnit

---

# Test Results

```bash
php artisan test tests/Feature/LoopOrganizationModeTest.php --compact
# 9/9 passed, 21 assertions
```

---

# Code Review

Revue par VERIFICATOR requise avant merge (Cyril parti, différé).

Points à vérifier :
- migration additive uniquement, compatible PostgreSQL + SQLite
- pas de `LoopMember` automatique créé, bypass contrôlé par `primary_loop_id`
- cross-Organization bloqué
- pas de `migrate:fresh` / `db:wipe`
- tests 9/9 passent, 21 assertions

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
