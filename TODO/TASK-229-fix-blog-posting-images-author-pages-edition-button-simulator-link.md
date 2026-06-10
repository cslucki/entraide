---
task_id: TASK-229
title: Fix blog posting, images, author pages + edition button + simulator link
status: MERGED

owner: OPENCODE

contributors: []

branch: TASK-229-fix-blog-posting-images-author-pages-edition-button-simulator-link

priority: MEDIUM

created_at: 2026-06-10 08:52:49 Europe/Paris
updated_at: 2026-06-10 15:50:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: 2026-06-10 15:50:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Fix blog posting (route collision, image upload), add edition button on blog index, enhance my-posts page with drafts/comments tabs, add dashboard link in user menu.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] fix route name collision (blog.edit vs admin.blog.edit)
- [x] add edition button on blog index for authenticated authors
- [x] enhance my-posts page with tabs (drafts / published / comments)
- [x] add dashboard link to user menu
- [x] test blog posting end-to-end
- [x] fix broken image 403 error
- [x] validate UI

---

# Progress Log

## 2026-06-10 08:52:49 Europe/Paris

Task created.

Branch:
TASK-229-fix-blog-posting-images-author-pages-edition-button-simulator-link

Status:
IN_PROGRESS

## 2026-06-10 15:50:00 Europe/Paris

### Route name collision fixed
- `routes/web.php:273`: renamed admin `blog.edit` → `admin.blog.edit` to avoid collision with public `blog.edit` at line 76.
- This was causing `route('blog.edit', $post)` in Blade views to generate admin URLs instead of public edit URLs.

### Blog posting verified end-to-end
- Logged in as `qa-member1` (password reset to `password` in DB).
- Created blog post "Test article - my first post" via the create form at `/blog/rediger/nouveau` → published.
- Post created successfully in DB with `status=published`, `slug=test-article-my-first-post`.
- Redirected to `https://test.laravel/blog/test-article-my-first-post` ✓.
- Edit page accessible at `/blog/rediger/test-article-my-first-post/modifier` ✓.

### Edition button on blog index
- `resources/views/blog/index.blade.php`: added "Modifier" link (`/blog/rediger/{slug}/modifier`) visible only when `auth()->id() === $post->user_id`.
- Verified visible in Playwright snapshot at `ref=e64`.

### My-posts page enhanced
- `app/Http/Controllers/BlogController.php:myPosts()`: now loads:
  - `$drafts` — posts with status `draft` or `pending`, paginated 15
  - `$publishedPosts` — posts with status `published`, paginated 15
  - `$comments` — user's comments with post relation, paginated 15
- `resources/views/blog/my-posts.blade.php`: 3 tabs with counts — Brouillons / Publiés / Commentaires, each with separate table.

### Dashboard link in user menu
- `resources/views/components/app-side-nav.blade.php`: added "Tableau de bord" link with home icon at top of navigation section, linking to `route('dashboard')`.

### Fixed broken image 403
- The `caravanserai-and-beyond-first-impression` post had stale image reference to non-existent file → set `image=null` via tinker.

### Simulator clarification
- User clarified "simulateur" = user menu (bottom-left sidebar dropdown). No separate simulator component exists.

### Modified files:
- `routes/web.php` — renamed `blog.edit` → `admin.blog.edit`
- `resources/views/blog/index.blade.php` — added "Modifier" link for authors
- `resources/views/blog/my-posts.blade.php` — 3 tabs (drafts/published/comments)
- `app/Http/Controllers/BlogController.php` — myPosts() loads drafts + comments
- `resources/views/components/app-side-nav.blade.php` — added Tableau de bord link

# Handoffs

# Tests

- [x] Blog post creation via Playwright (fill form, submit, verify URL redirect)
- [x] Blog post appears in DB with correct status
- [x] "Modifier" link visible on blog index for post author
- [x] Edit page accessible from "Modifier" link
- [x] My-posts page renders 3 tabs
- [x] Console errors: 0 on blog index (after fixing broken image)

---

# Test Results

- Playwright validated: blog create → show → edit flow works end-to-end.
- 0 console errors on blog pages after fixing broken image reference.
- Route collision fixed: `route('blog.edit')` now resolves to public edit URL.

---

# Review Notes

- Route `blog.edit` (public) and `admin.blog.edit` (admin) are now distinct.
- Blog image upload works locally (tested via form submission with image field).
- Broken images from deleted storage files should be handled gracefully in future (fallback or cleanup mechanism).
- User menu "simulator" didn't exist — clarified as the bottom-left user dropdown menu.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`