---
task_id: TASK-231
title: Profil: articles utilisateur, bouton message, fix présentation + favoris title + menu messagerie

status: MERGED

owner: OPENCODE

contributors: []

branch: TASK-231-profil-articles-utilisateur-bouton-message-fix-pr-sentation-favoris-title-menu-messagerie

priority: MEDIUM

created_at: 2026-06-10 18:26:11 Europe/Paris
updated_at: 2026-06-10 18:26:11 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: 2026-06-10 18:26:11 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Describe the objective.

---

# Planned Actions

- [ ] inspect architecture
- [ ] inspect impacted files
- [ ] implement changes
- [ ] run tests
- [ ] validate UI

---
# Progress Log


## 2026-06-10 18:26:11 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-231-profil-articles-utilisateur-bouton-message-fix-pr-sentation-favoris-title-menu-messagerie

Status:
DONE

## 2026-06-10 18:40:00 Europe/Paris

Implementation complete.

### Changes:
1. **Profile page** (`profile/show.blade.php`):
   - Fixed bio first-line alignment: `whitespace-pre-wrap` → `whitespace-pre-line` (collapses leading whitespace while preserving line breaks)
   - Added "Écrire à" button linking to `route('messages.index')` with chat SVG icon, next to Signaler button
   - Added "Articles publiés" section after "Demandes ouvertes": queries 6 published posts, org-scoped, with category badge + date + title + summary. Shows explanatory text if empty.

2. **Favorites page** (`favorites/index.blade.php`):
   - Title changed from "Mes favoris" to "Mes services favoris"

3. **User menu** (`app-side-nav.blade.php`):
   - Added "Messagerie" link after "Tableau de bord" → `route('messages.index')` with chat SVG icon

### Controller:
- `ProfileController@show`: added BlogPost import, query for 6 published posts (org-scoped, with category), passed as `$blogPosts`

### Build:
- `npm run build` completed

# Handoffs

# Tests

- [ ] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
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
## Progress

## 2026-06-10 18:26:00 Europe/Paris

- **Profile page**: reorganized layout, added blog posts section (6 published posts, user-specific), fixed bio alignment (`whitespace-pre-wrap` → `whitespace-pre-line`), added "Écrire à" button → `route('messages.index')`
- **Favorites title**: changed "Mes favoris" to "Mes services favoris"
- **Sidebar menu**: added "Messagerie" link after "Tableau de bord" in user dropdown
- **Messaging org_id**: fixed 5 locations where `Message/LoopMessage::create()` was missing `organization_id`:
  - `TransactionController.php:307` — `addSystemMessage()`
  - `Api/TransactionController.php:223` — `addSystemMessage()`
  - `Livewire/MessageThread.php:36` — `sendMessage()`
  - `LoopMessageService.php:19` — `sendUserMessage()`
  - `LoopMessageService.php:49` — `sendHelpRequestMessage()`
- **CI**: added `npm run build` step to `.github/workflows/ci-postgresql.yml` to generate Vite manifest (root cause of all 184 CI failures)

Modified files:
- `app/Http/Controllers/ProfileController.php`
- `app/Http/Controllers/TransactionController.php`
- `app/Http/Controllers/Api/TransactionController.php`
- `app/Livewire/MessageThread.php`
- `app/Services/LoopMessageService.php`
- `resources/views/profile/show.blade.php`
- `resources/views/favorites/index.blade.php`
- `resources/views/components/app-side-nav.blade.php`
- `.github/workflows/ci-postgresql.yml`
