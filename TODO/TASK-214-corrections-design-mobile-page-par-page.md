---
task_id: TASK-214
title: Corrections design mobile page par page

status: IN_PROGRESS

owner: OPENCODE

contributors: []

branch: TASK-214-corrections-design-mobile-page-par-page

priority: MEDIUM

created_at: 2026-06-06 09:34:05 Europe/Paris
updated_at: 2026-06-06 09:34:05 Europe/Paris

labels: []

lock:
  status: LOCKED
  agent: OPENCODE
  since: 2026-06-06 09:34:05 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Corriger progressivement les bugs de design mobile/PWA page par page.

Premier périmètre demandé par Cyril :
- rendre la navigation utilisateur accessible depuis l'avatar du topbar mobile ;
- afficher correctement les icônes dans la bottom-nav mobile.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement first mobile topbar/bottom-nav fixes
- [x] run frontend build
- [x] validate mobile UI
- [ ] collect next page-by-page design instructions

---
# Progress Log


## 2026-06-06 09:34:05 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-214-corrections-design-mobile-page-par-page

Status:
IN_PROGRESS

## 2026-06-06 09:37:22 Europe/Paris

Initial corrections implemented directly by OPENCODE after Cyril requested a new task for page-by-page design bugs.

Changes:
- `resources/views/components/mobile-topbar.blade.php`
  - Replaced the static avatar/profile link with an accessible user dropdown.
  - Added main navigation/account links already present in the desktop menu: dashboard, public profile, create service, create request, points, invitations, favorites, my posts, profile settings, admin when applicable, logout.
  - Changed the messages/notification icon from a non-navigating button to a link to `messages.index`.
  - Replaced unsupported Tailwind icon sizing class `w-4.5 h-4.5` with `w-5 h-5`.
- `resources/views/components/mobile-bottom-nav.blade.php`
  - Replaced unsupported Tailwind icon sizing classes `w-5.5 h-5.5` with `w-6 h-6`.
  - Removed unnecessary Alpine `:class` binding on static Blade-rendered nav links.
  - Added SVG stroke cap/join attributes and `aria-hidden="true"` for decorative icons.

Validation:
- `npm run build` passed.
- Playwright mobile 375x812 on `https://test.laravel/explorer` confirmed:
  - topbar user menu button is visible;
  - menu opens and exposes navigation links;
  - bottom-nav exposes icons and labels for Boucles, Échanges, Objectifs, Actus;
  - console has 0 errors.

Artifacts:
- Playwright screenshot: `task-214-mobile-menu.png`.

Notes:
- Browser warnings remain unrelated to this first correction: multiple Alpine instances and deprecated Apple mobile web app meta warning.
- `public/build` changed after local Vite build and is not intended as part of the source change unless finalization policy requires built assets.

# Handoffs

# Tests

- [ ] feature tests
- [x] browser validation
- [x] responsive validation
- [x] console inspection
- [ ] tenant validation

---

# Test Results

- 2026-06-06 09:36 Europe/Paris — `npm run build` passed with Vite.
- 2026-06-06 09:36 Europe/Paris — Playwright mobile 375x812 on `/explorer` passed for topbar menu opening and bottom-nav icon presence.
- 2026-06-06 09:36 Europe/Paris — browser console inspection: 0 errors, 2 warnings unrelated to these edits.

---

# Review Notes

- First slice only. Awaiting Cyril's next page-by-page design instructions before broadening the scope.
- Do not merge yet; TASK remains IN_PROGRESS and locked by OPENCODE.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
