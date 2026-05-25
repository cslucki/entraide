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

commits:
  - hash: 4de93e2
    run: RUN 1
    message: "task: RUN1 viability audit complete — verdict GO"
  - hash: 47f4a89
    run: RUN 3 + RUN 4
    message: "task: RUN3 (resolver observability) + RUN4 (seed/backfill Default Organization)"
  - hash: TBD
    run: RUN 5
    message: "task: RUN5 PHPUnit fix — OrganizationRouteCompatibilityTest 9/9 passed"

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
| RUN 0 — Bootstrap & Safety | ✅ DONE | Branch, cockpit, git state |
| RUN 1 — Viability Audit | ✅ DONE | 7 sub-agents, verdict GO |
| RUN 2 — Doctrine Documentation | ✅ DONE | 7 docs audités, 100% aligné |
| RUN 3 — Runtime Resolver Analysis | ✅ DONE | Architecture correcte, observabilité ajoutée |
| RUN 4 — Seed/Backfill | ✅ DONE | `db:seed`, `default_organization_id` set, SettingSeeder updaté |
| RUN 5 — PHPUnit | ✅ DONE | 9/9 passed, OrganisationRouteCompatibilityTest fixé |
| RUN 6 — Pages publiques | 🔄 NEXT | Homepage, /membres, /explorer, /blog |

## RUN 1 Résumé

**Verdict:** GO — stabilisation possible en ≤5 RUNs.
**Problème principal:** Base locale vide (0 communities, 0 settings). Pas une erreur de code.
**2 failures PHPUnit:** collision de routes `/org/{organization}` dans le test vs web.php.
**Architecture:** Existe et correcte. ResolveUrlOrganization a 3 fallbacks, tous échouent faute de données.
**DB audit:** 0 communautés, 0 settings, pas de table `organizations` (Organization extends Community).

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

| Run | File | Change |
|-----|------|--------|
| RUN3 | `app/Http/Middleware/ResolveUrlOrganization.php` | Added `Log::warning` when default org resolution fails |
| RUN3 | `app/Models/Scopes/BelongsToTenantScope.php` | Added `Log::warning` when whereRaw('0=1') activates |
| RUN4 | `database/seeders/SettingSeeder.php` | Added `default_organization_id` = first community |
| RUN5 | `tests/Feature/OrganizationRouteCompatibilityTest.php` | Changed test route prefix `/org/` → `/_test/org/` to avoid web.php collision |

## Tests

<!-- à remplir après exécution -->
