---
task_id: TASK-213
title: Explorer mobile adaptation layout cards bottom nav

status: DONE

owner: ORCHESTRATOR

contributors: []

branch: TASK-213-explorer-mobile-adaptation-layout-cards-bottom-nav

priority: MEDIUM

created_at: 2026-06-05 20:26:07 Europe/Paris
updated_at: 2026-06-05 20:26:07 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: 2026-06-05 20:34:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Adapter la page Explorer pour mobile : padding safe-areas, barre de filtres scrollable, bouton publier icon-only, cartes single-column, titre dynamique dans la topbar, safe-area bottom conditonnelle à auth.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implémenter : explorer.blade.php padding mobile, titre x-app-layout
- [x] implémenter : livewire explorer filtre scrollable, publier icon-only
- [x] implémenter : mobile-safe-bottom conditionnel auth
- [x] implémenter : AppLayout $title component property
- [x] run tests
- [x] validate UI Playwright mobile 375px

---
# Progress Log


## 2026-06-05 20:26:07 Europe/Paris

Task created.

## 2026-06-05 20:34:00 Europe/Paris

Implémentation terminée.

Changements:
- `app/View/Components/AppLayout.php` : ajout `$title` property (constructor promotion)
- `resources/views/layouts/app.blade.php` : `mobile-safe-bottom-auth` conditionnel, `filled($title)` pour titre
- `resources/views/explorer.blade.php` : padding mobile `py-4 sm:py-8`, titre `x-app-layout title="Échanges"`, `h1` hidden on mobile
- `resources/views/livewire/explorer.blade.php` : filtre `overflow-x-auto` scroll, `shrink-0` search, bouton publier icon-only mobile, cartes `gap-4 sm:gap-6` `active:scale`, skeleton 3 mobile / 6 desktop
- `ExplorerController.php` : revert `title` (passe par component attribute)

Owner:
ORCHESTRATOR

Branch:
TASK-213-explorer-mobile-adaptation-layout-cards-bottom-nav

Status:
IN_PROGRESS

# Handoffs

# Tests

- [x] feature tests
- [x] browser validation
- [x] responsive validation
- [x] console inspection
- [x] tenant validation

---

# Test Results

ExplorerTest: 17/17 pass
T126ExplorerTenantScopingTest: 6/6 pass
T0756BlogOrganizationScopingTest: 11/11 pass

Total: 28 tests pass (50 assertions)

Playwright mobile (375×812) :
- Topbar "Échanges": OK
- Bottom nav "Échanges" active: OK  
- Publier icon-only: OK
- Filtre scrollable: OK
- Cartes single-column: OK
- Safe areas padding: OK
- Console: 0 errors

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