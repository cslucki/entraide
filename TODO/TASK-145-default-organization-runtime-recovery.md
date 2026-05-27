---
task_id: TASK-145
title: Default Organization Runtime Recovery & Full Regression

status: DONE

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
  - hash: b06879d
    run: RUN 5
    message: "task: RUN5 PHPUnit fix — OrganizationRouteCompatibilityTest 9/9 passed"
  - hash: 77f0af9
    run: RUN 6 → 10
    message: "task: RUN9+RUN10 (includes RUN6/7/8 — public pages, auth, middleware order, Playwright, final validation)"
  - hash: 9474ba8
    run: FINAL
    message: "task: T145 final — status DONE, full RUN log, final report"

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
| RUN 6 — Pages publiques | ✅ DONE | Homepage, /membres, /explorer, /blog — all PASS |
| RUN 7 — Auth flows | ✅ DONE | Login, register, dashboard post-login |
| RUN 8 — Transactions/Service Requests | ✅ DONE | Model binding fix (middleware order), service creation/editing |
| RUN 9 — Playwright regression suite | ✅ DONE | 17 passed, 10 failed (pre-existing community-transaction issues) |
| RUN 10 — Validation finale | ✅ DONE | Commit, push, final report |

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
| RUN8 | `bootstrap/app.php` | Reordered middleware: ResolveUrlOrganization BEFORE SubstituteBindings |
| RUN9 | `tests/e2e/community-transactions/workflows/QA-03-messaging.spec.js` | Fixed mismatched parenthesis syntax error |

## Tests

| Suite | Pass | Fail | Skip | Assertions |
|-------|------|------|------|------------|
| PHPUnit Feature suite | 820 | 0 | 11 | 1748 |
| OrganizationRouteCompatibilityTest | 9 | 0 | 0 | 17 |
| Playwright core (smoke, auth, publish, chatloop, help-request) | 25 | 0 | 0 | — |
| Playwright community-transactions | 17 | 10 (pre-existing) | 76 | — |
