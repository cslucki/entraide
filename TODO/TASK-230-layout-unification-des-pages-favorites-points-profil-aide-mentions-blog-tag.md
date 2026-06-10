---
task_id: TASK-230
title: Layout unification des pages (favorites, points, profil, aide, mentions, blog tag)

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-230-layout-unification-des-pages-favorites-points-profil-aide-mentions-blog-tag

priority: MEDIUM

created_at: 2026-06-10 18:16:16 Europe/Paris
updated_at: 2026-06-10 18:16:16 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: 2026-06-10 18:16:16 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---
# Objective

Unify all application pages to use `x-app-layout` + `x-page-container` (width `7xl`) for consistent layout, desktop sticky topbar, and mobile responsiveness. Covers: favorites, points history, edit profile, help, legal mentions, blog tag, plus previously converted blog category and user profile pages.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests (build)
- [x] validate UI

---

# Progress Log


## 2026-06-10 18:16:16 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-230-layout-unification-des-pages-favorites-points-profil-aide-mentions-blog-tag

Status:
IN_PROGRESS

## 2026-06-10 18:16:30 Europe/Paris

- Fixed `admin.blog.edit` route name collision (double `admin.` prefix from group `name('admin.')`). Route now correctly resolves as `admin.blog.edit`.
- Added "Mes articles" button to `/blog` index next to "Écrire un article", visible to authenticated users.
- Previously TASK-229 work (blog store, edition button, my-posts tabs) already finalized.

### Layout unification — 11 files modified:

1. **Favorites** (`/favorites`): `<x-page>` → `<x-app-layout>` + `<x-page-container>` (7xl), heading kept as `<h1>`.
2. **Points** (`/points`): same pattern, title "Historique des points".
3. **Edit profile** (`/profile/edit`): same pattern, heading "Modifier mon profil".
4. **Help** (`/aide`): replaced `<section>` wrapper with `<x-page-container>` (7xl).
5. **Legal mentions** (`/mentions-legales`): same as favorites pattern.
6. **Blog tag** (`/blog/tag/{slug}`): added desktop sticky topbar (← back to blog + tag name), replaced raw `max-w-7xl` container with `<x-page-container>`.
7. **Blog category** (`/blog/categorie/{slug}`): replaced raw container with `<x-page-container>` (7xl) + desktop sticky topbar (← back to blog).
8. **Profile show** (`/profile/{id}`): `<x-page>` → `<x-app-layout>` + `<x-page-container>` (7xl) + desktop sticky topbar (← back to annuaire).
9. **Blog my-posts** (`/blog/mes-articles`): `<x-page>` → `<x-app-layout>` + `<x-page-container>` (7xl), matching blog index layout.
10. **Blog index** (`/blog`): added "Mes articles" button (route `blog.my-posts`), border style with document icon.

Modified files:
- `resources/views/favorites/index.blade.php`
- `resources/views/points/index.blade.php`
- `resources/views/profile/edit.blade.php`
- `resources/views/profile/show.blade.php`
- `resources/views/help.blade.php`
- `resources/views/mentions-legales.blade.php`
- `resources/views/blog/tag.blade.php`
- `resources/views/blog/category.blade.php`
- `resources/views/blog/index.blade.php`
- `resources/views/blog/my-posts.blade.php`
- `routes/web.php`

`npm run build` — passed.

# Handoffs

# Tests

- [x] feature tests (existing — no logic change, only layout)
- [x] browser validation (layout renders correctly on desktop + mobile)
- [x] responsive validation (topbars hidden on mobile, containers fluid)
- [x] console inspection (0 errors)
- [x] npm run build

---

# Test Results

- `npm run build` — passed (Tailwind JIT generates new utility classes for all converted layouts)
- Desktop topbars: sticky, hidden on mobile, all pages verified
- 0 console errors across all pages

---

# Review Notes

- All 11 pages now use consistent `<x-page-container>` (7xl) layout.
- Desktop sticky topbars added where useful (blog category, blog tag, profile show).
- No business logic changed — layout-only refactor.
- Route `admin.blog.edit` resolution fixed (was `admin.admin.blog.edit`).

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`