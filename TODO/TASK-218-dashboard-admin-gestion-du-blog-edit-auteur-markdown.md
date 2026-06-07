---
task_id: TASK-218
title: Dashboard Admin — Gestion du blog (edit, auteur, markdown)

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-218-dashboard-admin-gestion-du-blog-edit-auteur-markdown

priority: MEDIUM

created_at: 2026-06-07 09:10:13 Europe/Paris
updated_at: 2026-06-07 09:25:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: 2026-06-07 09:25:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Ajouter les fonctionnalités de gestion de blog dans le panneau admin :
- Édition d'un billet de blog
- Changement de l'auteur d'un billet
- Transformation Markdown → HTML (aperçu + rendu public)

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [ ] validate UI

---

# Progress Log

## 2026-06-07 09:10:13 Europe/Paris

Task created.

## 2026-06-07 09:25:00 Europe/Paris

Implémentation terminée :

### Routes ajoutées
- `GET /admin/blog/{post}/edit` → `admin.blog.edit`
- `PUT /admin/blog/{post}` → `admin.blog.update`
- `POST /admin/blog/preview-markdown` → `admin.blog.preview-markdown`

### Controller — AdminBlogController
- `edit()` — formulaire d'édition avec sélecteur d'auteur, catégories, tags
- `update()` — validation complète (user_id, title, slug, content, status, category_id, tags, meta)
- `previewMarkdown()` — rendu Markdown → HTML avec CommonMark + GFM

### Vue — admin/blog/edit.blade.php
- Onglets Alpine ✏️ Éditeur / 👁️ Aperçu
- Appel AJAX à `/admin/blog/preview-markdown` pour rendu en direct
- Selecteur d'auteur (tous les users)
- Slug éditable, statut radio, SEO collapsible
- Upload image, catégorie, tags, suppression

### Helpers
- `markdown()` dans `app/Support/helpers.php` — fonction globale CommonMark + GFM

### Markdown sur le blog public
- `blog/show.blade.php` : `{!! markdown($post->content) !!}` au lieu de `{!! nl2br(e($post->content)) !!}`

### Index admin
- Boutons "Modifier" et "Aperçu ⬀" ajoutés dans `admin/blog/index.blade.php`

### Package
- `league/commonmark:^2.8` installé
- Extension GitHub Flavored Markdown activée

---

# Handoffs

# Tests

- [x] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [ ] tenant validation

---

# Test Results

**13 nouveaux tests – 37 assertions – 100% vert**
- admin can access edit form
- non admin cannot access edit form
- guest cannot access edit form
- admin can update post title and content
- admin can change post author
- admin can update post status
- admin can destroy post
- preview markdown renders html
- non admin cannot preview markdown
- public blog show renders markdown
- edit form shows current author selected
- admin can update post with category and tags
- preview markdown requires authentication

**23 tests existants — 0 régressions**
- BlogPostPolicyTest (12 tests)
- T0756BlogOrganizationScopingTest (11 tests)

**Total: 36 tests, 74 assertions, 0 échecs**

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
