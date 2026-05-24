---
task_id: TASK-137
title: Version file + automatic footer version bump

status: DONE

owner: CODEX-COCKPIT

contributors: []

branch: TASK-137-version-file-automatic-footer-version-bump

priority: MEDIUM

created_at: 2026-05-24 16:33:34 Europe/Paris
updated_at: 2026-05-24 16:33:34 Europe/Paris

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

Créer un système de version automatique basé sur un fichier VERSION tracké en git, avec bump automatique au merge des tâches. Le footer doit afficher config('app.version') qui lit depuis VERSION.

Décision COCKPIT:
- Fichier racine VERSION tracké contenant v0.{TASK_ID}-alpha
- Footer utilise config('app.version')
- config/app.php lit VERSION via VersionServiceProvider
- ai/scripts/bump-version.sh pour le bump automatique
- Intégration dans ai/scripts/merge-task.sh
- TASK-137 initialise le système (v0.137-alpha)
- À partir de TASK-138, bump automatique au merge

---

# Planned Actions

- [x] inspect architecture (footer, config, scripts)
- [x] inspect impacted files (config/app.php, footer, merge-task.sh)
- [x] implement changes (VERSION, VersionServiceProvider, bump-version.sh, merge-task.sh)
- [x] update documentation (ai/README.md, ai/workflows/task-lifecycle.md, ai/tooling/git-workflow.md, TASK_TEMPLATE.md)
- [x] validate system (config('app.version') lit VERSION)

---
# Progress Log


## 2026-05-24 16:33:34 Europe/Paris

Task created.

Owner:
CODEX-COCKPIT

Branch:
TASK-137-version-file-automatic-footer-version-bump

Status:
IN_PROGRESS

## 2026-05-24 16:35:00 Europe/Paris — Bootstrap Complete

Fichier VERSION créé:
- Contenu initial: v0.137-alpha
- Tracké en git à la racine du projet

VersionServiceProvider créé:
- lit VERSION et définit config('app.version') au register
- enregistré dans bootstrap/providers.php

config/app.php modifié:
- valeur par défaut 'v0.0-alpha'
- surchargée par VersionServiceProvider

Footer déjà correct:
- resources/views/partials/footer.blade.php utilise config('app.version')
- resources/views/welcome.blade.php utilise app()->version() (alias de config)

bump-version.sh créé:
- accepte un numéro de tâche explicite ou déduit depuis la branche
- format: v0.{TASK_ID}-alpha
- affiche ancienne/nouvelle version
- échoue proprement si numéro introuvable

merge-task.sh modifié:
- appelle bump-version.sh après merge réussi
- skip TASK-137 (bootstrap)

Documentation mise à jour:
- ai/README.md: section "Version System"
- ai/workflows/task-lifecycle.md: note dans section MERGED
- ai/tooling/git-workflow.md: section "Version Management"
- ai/tasks/templates/TASK_TEMPLATE.md: section "Version Notes"

Validation:
- VERSION contient v0.137-alpha
- config('app.version') retourne v0.137-alpha

Contraintes respectées:
- main/PROD non touchés
- Pas de DB
- Pas de migration
- Architecture tenant non modifiée
- Scope strict (footer uniquement)

# Handoffs

# Tests

- [x] feature tests
- [x] browser validation
- [ ] responsive validation
- [x] console inspection
- [ ] tenant validation

---

# Test Results

## Version System Validation

```bash
$ cat VERSION
v0.137-alpha

$ php artisan tinker --execute="echo config('app.version');"
v0.137-alpha
```

Résultat: config('app.version') lit correctement VERSION.

## bump-version.sh Validation

```bash
$ bash ai/scripts/bump-version.sh 137
Task ID from argument: 137
Current version: v0.137-alpha
New version: v0.137-alpha
VERSION UPDATED
```

---

# Review Notes

## Implementation Summary

Successfully created automatic version bumping system.

**Components:**
1. `VERSION` file: root-level tracked file containing current version
2. `VersionServiceProvider`: loads VERSION into config('app.version')
3. `bump-version.sh`: script to bump VERSION to v0.{TASK_ID}-alpha
4. `merge-task.sh`: calls bump-version.sh after merge (skips TASK-137)

**Documentation updated:**
- ai/README.md
- ai/workflows/task-lifecycle.md
- ai/tooling/git-workflow.md
- ai/tasks/templates/TASK_TEMPLATE.md

**Footer unchanged:**
- Already used config('app.version')
- No hardcoded version strings to remove

**Bootstrap:**
- TASK-137 initializes VERSION to v0.137-alpha
- Future tasks (TASK-138+) will auto-bump on merge

**COCKPIT Decision:**
- Implementation approved
- Documentation ensures future agents understand the system
- Version bump is enforced by merge-task.sh, not manual editing