---
task_id: TASK-052
title: Modification configuration filesystem Laravel

status: IN_PROGRESS

owner: claude

contributors: [GLM]

branch: develop

priority: MEDIUM

created_at: 2026-05-07 17:55:23 Europe/Paris
updated_at: 2026-05-07 18:15:00 Europe/Paris

labels: []

lock:
  status: LOCKED
  agent: claude
  since: 2026-05-07 18:15:00 Europe/Paris

handoff: true

pr:
  status: NOT_READY
  url: null
---

# Objective

Résoudre le problème d'images de blog non accessibles en production sur Laravel Cloud.

**Problème:**
- Les images uploadées retournent 404 en production
- Le local fonctionne correctement
- Problème lié au stockage local + symlink `storage:link`

---

# Root Cause Analysis

## Confirmed Root Cause

1. **Uploaded files persist correctly on Laravel Cloud** - the storage layer works
2. **Issue was caused by non-persistent storage symlink** - `public/storage` is not recreated after deploy
3. **Laravel Cloud officially recommends Object Storage** instead of `storage:link` workflow
4. **Bucket created:** `entraide-main-public`
5. **Bucket connected as disk:** `public`

## Current Implementation

1. **Upload** (`BlogController.php:121`, `170`):
   ```php
   $data['image'] = $request->file('image')->store('blog', 'public');
   ```
   Stocke dans le disk `public`

2. **Affichage** (`blog/show.blade.php`):
   ```php
   <img src="{{ asset('storage/' . $post->image) }}">
   ```
   Dépend du symlink `public/storage` → `storage/app/public`

3. **Configuration** (`config/filesystems.php`):
   - Disk `public` → `storage_path('app/public')` (LOCAL)
   - S3 bucket `entraide-main-public` créé et connecté

## Why Production Fails

Laravel Cloud recrée les conteneurs à chaque déploiement. Le symlink `storage:link` n'est pas maintenu entre les déploiements.

---

# Solution

Laravel Cloud injecte automatiquement la configuration S3. Le bucket `entraide-main-public` est configuré comme disk `public`.

**Changes made:**

1. **config/filesystems.php** - Updated `public` disk to:
   - Use `s3` driver when `AWS_ACCESS_KEY_ID` is present (Laravel Cloud)
   - Use `local` driver otherwise (local development)
   - URL configured to use `AWS_PUBLIC_URL` when available

2. **.env.example** - Added documentation for Laravel Cloud storage:
   - Explains automatic S3 configuration
   - Documents `STORAGE_PUBLIC_DRIVER`, `AWS_PUBLIC_BUCKET`, `AWS_PUBLIC_URL`

3. **tests/Feature/CommunityModelTest.php** - Made test more flexible:
   - Changed from exact `/storage/` URL check to file path content check
   - Now works with both local and S3 URLs

---

# Planned Actions

- [x] switch to develop branch
- [x] install league/flysystem-aws-s3-v3 (already installed)
- [x] update filesystems.php for Laravel Cloud
- [x] update .env.example with S3 configuration
- [x] update test for compatibility
- [ ] commit changes
- [ ] create feature branch from develop
- [ ] test locally (verify local storage still works)
- [ ] validate production deployment

---

# Progress Log

## 2026-05-07 18:15:00 Europe/Paris

**Implementation completed:**

Changes made (minimal architecture changes):
1. `config/filesystems.php` - Public disk now uses S3 on Laravel Cloud
2. `.env.example` - Added Laravel Cloud storage documentation
3. `tests/Feature/CommunityModelTest.php` - Made test environment-agnostic

**Files changed:**
- `.env.example` (+11 lines)
- `config/filesystems.php` (+21 lines, -3 lines)
- `tests/Feature/CommunityModelTest.php` (+1 line, -1 line)

**Next steps:**
1. Commit changes to develop
2. Create feature branch
3. Test locally (verify local storage still works)
4. Deploy to Laravel Cloud for validation

## 2026-05-07 18:10:00 Europe/Paris

**Root cause confirmed:**

* Uploaded files persist correctly on Laravel Cloud
* Issue was caused by non-persistent storage symlink
* Laravel Cloud officially recommends Object Storage instead of storage:link
* Bucket created: `entraide-main-public`
* Bucket connected as disk: `public`

**Next steps:**
1. Work only from develop branch
2. Install: `composer require league/flysystem-aws-s3-v3 "^3.0" --with-all-dependencies`
3. Inspect existing upload implementation
4. Verify compatibility with Laravel Cloud Object Storage
5. Preserve current upload architecture as much as possible
6. Avoid unnecessary controller/model/blade refactors
7. Prefer minimal safe modifications
8. Verify Storage::url() behavior

**Important constraints:**
* Do NOT reintroduce storage:link workflow
* Do NOT implement manual symlink hacks
* Do NOT over-engineer S3 migration
* Laravel Cloud already injects bucket configuration automatically

**Goal:**
Make blog image uploads production-safe and persistent across deploys with minimal architecture changes.

## 2026-05-07 18:05:00 Europe/Paris

Root cause identifié (initial - avant architecture validation):
Le problème vient du fait que Laravel Cloud ne persiste pas les fichiers dans `storage/` entre les déploiements et ne recrée pas automatiquement le symlink `storage:link`.

## 2026-05-07 17:55:23 Europe/Paris

Task created.

Owner: GLM

Branch:
TASK-052-modification-configuration-filesystem-laravel

Status:
IN_PROGRESS

# Handoffs

GLM → claude @ 2026-05-07 18:15:00

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
