---
task_id: TASK-143
title: T140.4 Routes /org/{organization} en parallèle des routes legacy /{community}

status: MERGED

owner: CYRIL

contributors: []

branch: TASK-143-t140-4-org-routes-parallel

priority: MEDIUM

created_at: 2026-05-24 21:00:00 Europe/Paris
updated_at: 2026-05-24 22:15:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: ''
  since: ''

handoff: false

pr:
  status: NOT_READY
  url: null
---

# T140.4 — Routes /org/{organization} en parallèle des routes legacy /{community}

## Statut

MERGED

## Objectif

- Ajouter des routes organization-first /org/{organization} en parallèle des routes legacy /{community}
- Aucune route legacy supprimée
- Aucun redirect
- Aucune modification DB
- Aucune modification controller métier
- Patch minimal ResolveUrlOrganization pour éviter double-binding

## Pré-requis validés

- T140.3 fallback gates terminé
- ResolveOrganization middleware alias existe dans bootstrap/app.php
- Placeholder commenté dans routes/web.php lignes 268-270
- Sous-agents A/B/C/D exécutés
- Synthèse _temp/T140.4-pre-flight.md validée

## Fichiers modifiés

- `routes/web.php` — Ajout groupe /org/{organization} en parallèle
- `app/Http/Middleware/ResolveUrlOrganization.php` — Patch isCommunityPrefixedRoute()
- `tests/Feature/T1404OrganizationParallelRoutesTest.php` — CRÉATION
- `tests/Feature/T1392KnownRisksTest.php` — Nouveaux known-risks
- `docs/audits/T140.4-parallel-organization-routes.md` — CRÉATION

## Routes ajoutées

57 routes sous /org/{organization} prefix, nommées organization.* :
(55 routes métier + 2 routes auth POST login et register explicitement nommées)
- organization.home
- organization.login / organization.register (GET + POST nommé) / organization.password.*
- organization.dashboard / organization.logout
- organization.services.* (create, store, edit, update, destroy, show)
- organization.requests.* (create, store, destroy, show)
- organization.transactions.* (export, store, approve, refuse, adjust, cancel, complete, confirm, contest)
- organization.reviews.store
- organization.messages.* (index, show)
- organization.points.index
- organization.favorites.* (index, toggle)
- organization.reports.* (service, request, user)
- organization.profile.* (edit, update, availability, destroy, show)
- organization.loops.* (index, create, store, show, join, leave, members.add, messages.store, help-request.analyze, help-request.publish)
- organization.explorer
- organization.members.index
- organization.exchanges.index

## Routes legacy conservées

Toutes les routes /{community} sont conservées à l'identique.

## Interdits respectés

- Aucune suppression route legacy
- Aucun redirect 301
- Aucune modification DB
- Aucune modification controller métier
- Aucun changement CurrentOrganization
- Aucun renommage community.*
- Aucun changement SEO/canonical

## Tests ajoutés

- **tests/Feature/T1404OrganizationParallelRoutesTest.php** — 15 tests T1404 (25 assertions)
- **tests/Feature/T1392KnownRisksTest.php** — +3 known-risks (11: redirect, 12: dépréciation, 13: duplicate content SEO) = 13 known-risks au total (2 passed, 11 skipped)
- **tests/Feature/T1392RouteSmokeGatesTest.php** — 35 smoke gates au total (6 nouveaux `/org/`)

## Résultats finaux

- **811 passed / 11 skipped / 0 failed**
- Toutes les routes legacy intactes
- Aucune régression

## Progress Log

2026-05-24 22:05 Europe/Paris — 4 corrections appliquées : nommage 2 routes POST login/register, alignement docs sur 57 routes/15 tests, fix mojibave accents T1392KnownRisksTest, +6 smoke tests /org/ dans T1392RouteSmokeGatesTest. Status DONE, UNLOCKED. GO commit donné.

2026-05-24 22:03 Europe/Paris — Cycle corrections terminé : 811 passed / 11 skipped / 0 failed. En attente GO commit.

2026-05-24 22:00 Europe/Paris — NO-GO reçu avec 4 corrections demandées.

2026-05-24 21:45 Europe/Paris — Implementation terminée. 805 passed / 11 skipped / 0 failed. En attente validation Stéphane.

2026-05-24 21:30 Europe/Paris — Routes /org/{organization} ajoutées. T1404 test (15), KnownRisks (+3), architecture doc créés.

2026-05-24 21:10 Europe/Paris — Sous-agents A/B/C/D terminés. Synthèse _temp/T140.4-pre-flight.md validée.

2026-05-24 21:00 Europe/Paris — Tâche créée. Branche depuis develop (post-merge T140.3).

## Version

v0.137-alpha (inchangé)

## Notes

- Le middleware ResolveOrganization (alias de ResolveCommunity) est enfin utilisé
- Patch ResolveUrlOrganization ::isCommunityPrefixedRoute() ajoute le check organization param
- Toutes les routes utilisent les mêmes controllers que legacy
- Toutes les routes utilisent le même middleware stack
