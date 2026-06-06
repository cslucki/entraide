---
task_id: TASK-215
title: Uniformiser gabarit body desktop sur toutes les pages
status: MERGED
owner: OPENCODE
contributors: []
branch: TASK-215-uniformiser-gabarit-body-desktop-sur-toutes-les-pages
priority: MEDIUM
created_at: 2026-06-06 22:42:38 Europe/Paris
updated_at: 2026-06-06 23:40:00 Europe/Paris
labels:
  - component
  - template
  - ui
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

Uniformiser le gabarit body desktop sur toutes les pages via un composant `<x-page>` canonical, remplaçant les patterns hétérogènes (A: `<x-app-layout>` + `<x-page-container>`, B: Breeze legacy `$header` slot, C: manuel `<div class="max-w-...">`).

---

# Done

- [x] inspect architecture — 3 patterns identifiés (A, B, C)
- [x] create `<x-page>` component with props: title, heading, width (default 7xl), headingActions slot
- [x] update `<x-page-container>` to accept `width` prop
- [x] migrate 13 pages: points/index, services/create, requests/create, favorites/index, services/show, requests/show, profile/show, blog/my-posts, blog/create, profile/edit, mentions-legales, loops/index, loops/show
- [x] fix stray `</div>` tags in 6 pages (points, favorites, blog/my-posts, blog/create, services/create, requests/create)
- [x] fix stray `</x-page-container>` in profile/show.blade.php (caused 500 error)
- [x] set explicit width on 4 pages missing it (profile/show 4xl, profile/edit 3xl, mentions-legales 4xl, loops/index 5xl)
- [x] run Playwright tests — 12/12 passed on chromium
- [x] cross-browser validation — 36/36 passed on chromium+firefox+webkit+mobile-chrome

---

# Progress Log

## 2026-06-06 22:42:38 Europe/Paris
Task created. Branch: TASK-215-uniformiser-gabarit-body-desktop-sur-toutes-les-pages.

## 2026-06-06 ~22:50
Audit architecture templates. 3 patterns identifiés: canonical A, Breeze legacy B, manuel C.

## 2026-06-06 ~23:00
Création composant `<x-page>` et `<x-page-container>` avec prop `width`.

## 2026-06-06 ~23:10
Migration 13 pages vers `<x-page>`.

## 2026-06-06 ~23:15
Nettoyage : supprimé `</x-page-container>` orphelin dans profile/show. Supprimé 6 `</div>` orphelins.

## 2026-06-06 ~23:20
Playwright tests : 36/36 passés sur 4 browsers.

## 2026-06-06 ~23:30
Ajout width explicite sur 4 pages manquantes.

## 2026-06-06 23:40:00 Europe/Paris
Finalisation. Status DONE, lock UNLOCKED.

# Handoffs

None.

# Tests

- [x] Playwright chromium — 12 tests, 12 passed
- [x] Playwright firefox — 12 tests, 12 passed
- [x] Playwright webkit — 12 tests, 12 passed
- [x] Playwright mobile-chrome — 12 tests, 12 passed
- [x] console error validation — all pages clean

# Test Results

```
$ npx playwright test tests/e2e/task-215-page-migration.spec.js --project=chromium --project=firefox --project=webkit --project=mobile-chrome --reporter=list
36 passed (1.2m)
```

# Review Notes

- Composant `<x-page>` canonical : wrapper unique combinant layout + container + heading desktop + titre SEO
- Toutes les pages utilisent `<x-page>` comme balise ouvrante et `</x-page>` comme fermeture
- Largeurs appliquées : 3xl (forms), 4xl (show pages, mentions), 5xl (grid lists), 7xl (default)
- Aucune classe PHP nécessaire — composant Blade simple
- Migration backwards-compatible
- loops/show non testé (UUID invalide pour le membre de test)

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
