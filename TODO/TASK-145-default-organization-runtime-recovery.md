---
task_id: TASK-145
title: Default Organization Runtime Recovery & Full Regression

status: IN_PROGRESS

owner: PROJECT_SUPERVISOR

contributors:
  - DEFAULT_ORGANIZATION_ARCHITECT
  - ROOT_DOMAIN_RUNTIME_AUDITOR
  - TENANT_SCOPE_AUDITOR
  - PUBLIC_PAGES_PLAYWRIGHT_AUDITOR
  - AUTH_FLOW_AUDITOR
  - DATA_BACKFILL_AUDITOR
  - PHPUnit_FAILURE_AUDITOR

branch: TASK-145-default-organization-runtime-recovery

priority: HIGH

created_at: 2026-05-25
updated_at: 2026-05-25

labels:
  - organization
  - runtime
  - regression
  - stabilization

lock:
  status: UNLOCKED
  agent: PROJECT_SUPERVISOR
  since: 2026-05-25

---

# T145 — Default Organization Runtime Recovery & Full Regression

## Status

| Phase | Status | Detail |
|-------|--------|--------|
| RUN 0 — Bootstrap & Safety | IN PROGRESS | Branch, cockpit, git state |
| RUN 1 — Viability Audit | PENDING | 7 sub-agents |
| RUN 2+ — Doctrine/Implementation | LOCKED | Wait for Cyril GO |

## Symptômes rapportés

- 2 failures: `OrganizationRouteCompatibilityTest` (302 redirect instead of 200)
- 824 passed, 11 skipped
- Homepage counters incorrect
- Pages principales vides ou 404
- `/membres`, `/explorer`, `/blog` potentiellement cassés

## Hypothèse principale

`current_organization` est null sur routes métier racine → scopes filtrent tout → redirect ou contenu vide.

## Doctrine

Le runtime DOIT résoudre une Default Organization réelle sur toute route métier. Domain racine n'est pas tenantless pour routes métier.

## Modified Files

<!-- à remplir après implémentation -->

## Tests

<!-- à remplir après exécution -->
