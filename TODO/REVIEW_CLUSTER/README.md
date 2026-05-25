# Review Cluster — T140

Cluster d'audit indépendant pour les tâches T140 / T140.5.

## Objectif

Auditer le code de T140.5A-D sans perturber le PROJECT_SUPERVISOR ni modifier le runtime applicatif.

## Règles absolues

- **AUCUNE modification du runtime** : `app/`, `routes/`, `resources/`, `database/`, `tests/`, `VERSION`
- **Lecture seule** : tous les agents sont en mode read-only
- **Interdiction de commit/merge** : sur le runtime applicatif
- **Documentation only** : rapports dans `TODO/REVIEW_CLUSTER/AGENTS/*/REPORT.md`

## Structure

```
TODO/REVIEW_CLUSTER/
├── README.md
├── MISSION.md
├── TOOLING.md
├── REVIEW_WORKFLOW.md
├── BOOTSTRAP_REPORT.md
└── AGENTS/
    ├── REVIEW_ARCHITECT/
    │   └── REPORT.md
    ├── STATIC_ANALYZER/
    │   └── REPORT.md
    ├── TENANT_SAFETY_REVIEWER/
    │   └── REPORT.md
    ├── LARAVEL_REVIEWER/
    │   └── REPORT.md
    └── PLAYWRIGHT_REVIEWER/
        └── REPORT.md
```

## Branche

- `TASK-144-review-cluster-tooling` — installation/configuration outillage only
- Aucune modification sur les branches T140.5 actives

## Point de contact

Arrêt obligatoire après bootstrap pour validation humaine (Cyril).