# Mission — T140 Review Cluster

## Contexte

Projet Laravel : `/home/cyril/claude-code/sites/test.laravel`

Objectif : créer un cluster de review indépendant pour auditer T140 / T140.5, sans perturber le PROJECT_SUPERVISOR.

## Contraintes

### Règle absolue

Tu ne modifies pas le runtime applicatif.

**Interdits** :
- `app/`
- `routes/`
- `resources/`
- `database/`
- `tests/`
- `VERSION`
- branches T140.5 actives
- fichiers TASK runtime existants

### Branche dédiée obligatoire

Cette branche (`TASK-144-review-cluster-tooling`) sert uniquement à :
- installer/configurer l'outillage review
- documenter le cluster
- créer les rapports d'audit

### Dossier de travail

`TODO/REVIEW_CLUSTER/` — uniquement pour documentation et rapports d'audit

## Outils disponibles (via Laravel Boost)

- **PHPStan** 2.1.55 — analyse statique PHP
- **Rector** 2.4.4 — analyse et refactoring automatique (dry-run only)
- **Pint** 1.29.1 — linter Laravel (test mode only)
- **Laravel Boost MCP** — introspection Laravel

## Workflow

1. **Bootstrap** : installation outillage et documentation
2. **Audit** : lecture seule, rapports dans `AGENTS/*/REPORT.md`
3. **Validation** : point de rendez-vous humain
4. **Recommandations** : proposition d'améliorations, pas de modifications

## Point de rendez-vous humain obligatoire

**Quand** : après création du dossier `TODO/REVIEW_CLUSTER/` et installation/configuration outillage.

**Pourquoi** : valider que le Review Cluster est bien séparé du PROJECT_SUPERVISOR et qu'il n'a pas modifié le runtime.

**Avec qui** : Cyril.

**Effet** :
- ne lance pas encore la review complète T140
- ne corrige rien
- ne merge rien
- attends validation humaine