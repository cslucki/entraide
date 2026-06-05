---
task_id: TASK-212
title: Design PWA mobile layout topbar bottom nav Explorer chip bar

status: IN_PROGRESS

owner: CODEUR

contributors: []

branch: TASK-212-design-pwa-mobile-layout-topbar-bottom-nav-explorer-chip-bar

priority: MEDIUM

created_at: 2026-06-05 20:01:57 Europe/Paris
updated_at: 2026-06-05 20:01:57 Europe/Paris

labels: []

lock:
  status: LOCKED
  agent: CODEUR
  since: 2026-06-05 20:01:57 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Objectif: Implémenter le design PWA mobile selon le gabarit du graphiste (_temp/PWAfiles/).
Approche: composants Blade mobiles réutilisables intégrés à l'architecture Laravel existante (pas de SPA full).

## Périmètre
- PWA manifest + meta tags iOS
- Service Worker (cache shell offline, API network-only)
- Composants mobiles : topbar, bottom nav (4 onglets), FAB "+"
- Layout adapté avec safe areas iOS
- Desktop inchangé (components mobiles masqués en md:block)

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes (Phase 1: PWA mobile shell)
- [x] run tests (T0756 11/11, ExplorerTest 11/11)
- [ ] validate UI (Playwright)
- [ ] browser validation
- [ ] responsive validation

---
# Progress Log


## 2026-06-05 20:02:00 Europe/Paris — Phase 1: PWA mobile shell

Gabarit graphiste fourni dans _temp/PWAfiles/. Adapté pour architecture Laravel existante.

Réalisé:
1. PWA manifest: site.webmanifest mis à jour (theme_color #1B1FCC, display standalone, orientation portrait)
2. Metas iOS: viewport-fit=cover, apple-mobile-web-app-capable, apple-mobile-web-app-status-bar-style black-translucent
3. Service Worker: public/sw.js créé (cache shell, API/Livewire network-only, cache-first assets, offline fallback)
4. SW registration: ajoutée dans resources/js/app.js (load event)
5. mobile-topbar: titre, notifications badge, avatar initiales, safe area top
6. mobile-bottom-nav: 4 onglets (Boucles/Échanges/Objectifs/Actus), routes Laravel, safe area bottom
7. mobile-fab: bouton "+" flottant avec dropdown 3 options (Demander/Proposer/Écrire)
8. Layout app.blade.php: mobile components ajoutés (md:hidden), desktop inchangé
9. Build Vite: OK (55 modules, CSS 90kB, JS 84kB)
10. Tests passent: T0756 11/11, ExplorerTest 11/11

Fichiers modifiés (9, +288/-10):
- public/site.webmanifest
- public/sw.js (nouveau)
- resources/js/app.js
- resources/views/layouts/app.blade.php
- resources/views/components/mobile-topbar.blade.php (nouveau)
- resources/views/components/mobile-bottom-nav.blade.php (nouveau)
- resources/views/components/mobile-fab.blade.php (nouveau)
- TODO/TASK-212-*.md (nouveau)
- public/build/manifest.json

# Handoffs

# Tests

- [x] feature tests (T0756 11/11, ExplorerTest 11/11)
- [ ] browser validation (Playwright)
- [ ] responsive validation (breakpoints md)
- [x] console inspection (build OK)
- [ ] tenant validation

---

# Test Results

Pending.

---

# Review Notes

Pending.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`