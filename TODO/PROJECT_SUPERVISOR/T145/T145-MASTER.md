# T145-MASTER.md — Default Organization Runtime Recovery

**Owner:** PROJECT_SUPERVISOR
**Status:** RUN 0 DONE, RUN 1 IN PROGRESS
**Branche:** `TASK-145-default-organization-runtime-recovery`
**Date:** 2026-05-25

---

## Strategic Objective

Déterminer si le runtime Peacasso peut être réaligné proprement avec la doctrine Default Organization (Organization = Tenant, toute route métier résout une Organization réelle), puis le stabiliser avec QA Playwright.

## Doctrine Fondamentale

- Organization = Tenant. Frontière de sécurité unique.
- Loop ≠ Tenant.
- Community/community_id reste legacy technique temporaire.
- Domaine racine n'est pas tenantless pour routes métier.
- Public ≠ Global. Une route publique peut être Organization-scopée.
- `current_organization` null sur route métier = bug.
- Routes Platform globales autorisées: `/`, `/login`, `/register`, `/password/*`, `/mentions-legales`, `/sitemap.xml`, `/partenaires`, `/admin/*`.
- Routes métier racine DOIVENT résoudre une Default Organization: `/membres`, `/explorer`, `/blog`, `/services`, `/requests`, `/loops`, `/messages`, `/transactions`, `/dashboard`.

## Plan Global

| RUN | Phase | Description | Statut |
|-----|-------|-------------|--------|
| 0 | Bootstrap & Safety | Vérifier état, créer cockpit | ✅ |
| 1 | Viability Audit READ-ONLY | 7 sub-agents, verdict GO/ATTENTION/NO-GO | 🔶 |
| 2 | Doctrine Documentation | Docs architecture (read-only) | 🔒 |
| 3 | Default Organization Runtime Resolver | Garantir current_organization | 🔒 |
| 4 | Default Organization Seed / Backfill | Existence DB d'une Default Organization | 🔒 |
| 5 | PHPUnit Compatibility Fix | Corriger 2 failures | 🔒 |
| 6 | Public Pages Runtime Fix | Pages métier racine | 🔒 |
| 7 | Authenticated Flows Runtime Fix | Login, dashboard, redirects | 🔒 |
| 8 | Transaction / Service Request Flow | Parcours métier | 🔒 |
| 9 | Playwright Regression Suite | Smoke tests automatisés | 🔒 |
| 10 | Final Industrial Validation | optimize, route:cache, npm build, tests | 🔒 |

## Conditions de Merge

- `php artisan optimize` ✅
- `php artisan route:cache` ✅
- `npm run build` ✅
- `php artisan test` ✅ (ou échecs préexistants documentés)
- Playwright smoke ✅ (ou réserves documentées)
- `git status` clean
- `T145-FINAL-REPORT.md` complet
- `T145-MASTER.md` à jour
- `finalize-task.sh` OK

## Règles

- Aucun main
- Aucun PROD
- Aucune migration destructive
- Commits petits et lisibles
- Push régulier
- TASK file à jour
- Tests après chaque patch important
- Playwright obligatoire après RUN 6+

## Risques

| Risque | Probabilité | Impact | Mitigation |
|--------|-------------|--------|------------|
| Default Organization conflict avec auth user org | HAUT | BLOCKANT | Doctrine claire : Default Org ≠ User Org |
| Refonte middleware impacter auth | MOYEN | HAUT | RUN séparé, tests auth obligatoires |
| Seed DB nécessaire en local | FAIBLE | MOYEN | Migration minimale documentée |
| Scope BelongsToTenantScope vide | MOYEN | HAUT | Testé dans RUN 6, debug scope |
| Playwright tests trop fragiles | MOYEN | MOYEN | Réécriture si besoin (RUN 9) |
