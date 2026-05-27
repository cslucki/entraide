# T145-PLAYWRIGHT-PLAN.md — Playwright Plan

**RUN 9 target.** Smoke tests to be created/rewritten after runtime stabilization.

## Couverture minimale

1. Navigation publique : `/`, `/membres`, `/explorer`, `/blog`
2. Pages métier publiques Organization-scopées : `/org/{org}/*`
3. Login : `/login`, `/org/{org}/login`
4. Dashboard connecté
5. Création demande/service
6. Création transaction ou parcours équivalent
7. Console errors check on all pages
8. Screenshots (desktop + mobile)
9. Dark mode screenshots

## Statut actuel

- Aucun test Playwright opérationnel (anciens tests supprimés/fragiles)
- Nouvelle suite à écrire dans RUN 9
- Format: `tests/Playwright/` (selon convention projet)
