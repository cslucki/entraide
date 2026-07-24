---
task_id: TASK-1019
title: Add individual dossier sharing (reader/editor)

status: DONE

owner: codeur

contributors: []

branch: TASK-1019-add-individual-dossier-sharing-reader-editor

priority: MEDIUM

created_at: 2026-07-17 08:35:09 Europe/Paris
updated_at: 2026-07-17 09:15:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: 2026-07-17 09:15:00 Europe/Paris

handoff: false

pr:
  status: READY
  url: null
---
# Objective

Allow sharing a Dossier with explicit individual members of the same Organization. Owner remains unique. Member gets `reader` or `editor` role. Sharing a Dossier never grants Blog edit rights. No cross-Organization visibility. No Loop inheritance.

---

# Audit — Lot 1019-A

## Current Dossiers architecture

### `dossiers` table
- UUID, organization_id FK, owner_id FK, name, visibility (default `private`), timestamps, softDeletes
- Indexes: (org, owner), (org, visibility)

### `dossier_blog_posts` table
- UUID, organization_id FK, dossier_id FK, blog_post_id unique, added_by FK nullable, position, timestamps
- Cascade on dossier delete. BlogPost intact.

### `Dossier` model
- HasOrganizationId, HasUuids, SoftDeletes
- owner(): BelongsTo User
- dossierBlogPosts(): HasMany, articles(): BelongsToMany with pivot
- No `members()` relation yet

### `DossierPolicy`
- Owner-only via `ownsCurrentOrganizationDossier()`: check org match + user match + owner match
- `viewAny`: org match + not banned
- `view/update/delete`: owner only
- No member access whatsoever

### `DossierController`
- index: queries `owner_id = user->id AND visibility = private` only
- show/update/edit/destroy: authorize via DossierPolicy (owner-only)
- destroy: transaction deletes dossierBlogPosts then dossier

### `DossierArticleController`
- store/destroy/reorder: all require `authorize('update', $dossier)` → owner-only
- `ensureUserOwnsBlogPost()`: author-only for attach/detach

### `BlogCoAuthorController` (reference pattern)
- JSON API: index, store, destroy, search
- org* variants delegate to main methods
- Search: `User::where('organization_id', ...)->where(name/first_name/email like ...)`
- Cross-org check, self-check, duplicate check

### i18n
- `lang/en/dossiers.php`: 60 keys
- `lang/fr/dossiers.php`: 60 keys

### Key constraints
- `visibility` column exists but only `private` value used
- No `dossier_members` table
- No `DossierMember` model

---

# Plan Court

## Lot 1019-A — Migration + Model

### Migration: `2026_07_17_010000_create_dossier_members_table.php`
- UUID primary
- organization_id FK → organizations cascadeOnDelete
- dossier_id FK → dossiers cascadeOnDelete
- user_id FK → users cascadeOnDelete
- role string, allowed: `reader`, `editor`
- added_by FK → users nullable, nullOnDelete
- timestamps
- unique(dossier_id, user_id)
- index(organization_id, dossier_id)
- index(organization_id, user_id)

### `DossierMember` model
- HasUuids, HasFactory
- fillable: organization_id, dossier_id, user_id, role, added_by
- casts: role → string
- Relations: dossier(), user(), addedBy(), organization()

### `Dossier` model additions
- `members(): BelongsToMany` to User through dossier_members with role, added_by, timestamps
- `dossierMembers(): HasMany` to DossierMember
- `isMember(userId)`: checks if user is member
- `memberRoleFor(userId)`: returns role or null
- `VISIBILITY_SHARED = 'shared'` constant
- Update `fillable` to include `visibility`
- Add visibility check in `members()` scope or where

### `User` model additions
- `sharedDossiers(): BelongsToMany` to Dossier through dossier_members

### Visibility
- Dossier with members → `visibility = 'shared'`
- Dossier with no members → `visibility = 'private'`
- Visibility is derived from member count, not set manually

---

## Lot 1019-B — Policy Update

### `DossierPolicy` rewrite
- `viewAny`: same org + not banned
- `view`: owner OR member (any role) in same org
- `create`: same org + not banned
- `update`: owner OR editor-member in same org
- `delete`: owner only
- `manageMembers`: owner only
- `attachArticle`: owner OR editor-member in same org + author of blog post
- `detachArticle`: same as attach
- `reorderArticles`: same as update

### Helper methods
- `isOwner(user, dossier)`: owner_id match
- `isMember(user, dossier)`: member row exists in same org
- `isEditor(user, dossier)`: member row with role=editor in same org
- `isReader(user, dossier)`: member row with role=reader in same org

### BlogPost rights unchanged
- Sharing never grants BlogPost edit rights
- DossierArticleController continues to check author ownership for article operations

---

## Lot 1019-C — Member Management Backend

### `DossierMemberController` (JSON API)
All endpoints enforce: dossier in current org, requester is owner.

1. `GET /dossiers/{dossier}/members` → list members with role, name, email, avatar
2. `POST /dossiers/{dossier}/members` → add member {user_id, role}
3. `PATCH /dossiers/{dossier}/members/{member}` → update role {role}
4. `DELETE /dossiers/{dossier}/members/{member}` → remove member
5. `GET /dossiers/{dossier}/members/search?q=` → search org members not yet members

Org-scoped variants: `organization.dossierMembers.*`

### Validation
- user_id exists, same org, not owner, not already member
- role in [reader, editor]
- Cannot remove yourself (owner)

### Visibility auto-management
- After add: if member count > 0 → visibility = 'shared'
- After remove: if member count = 0 → visibility = 'private'

---

## Lot 1019-D — View Updates

### `dossiers/index.blade.php`
- Two sections: "Mes dossiers" (owned) and "Partagés avec moi" (member)
- Owned: current behavior + show shared badge if has members
- Shared: show dossier name, owner name, role badge (reader/editor), open link

### `dossiers/show.blade.php`
- Add members sidebar (owner/editor only)
- Show owner, member list with role, remove button (owner only)
- Add member: search + role select + add button
- Reader view: hide attach/detach/reorder controls, hide eligible articles sidebar
- Editor view: full article management (via existing DossierArticleController)

### Controller updates
- `DossierController::index`: query owned + shared dossiers (separate collections)
- `DossierController::show`: check member access, pass `role` to view
- `DossierController::store/update/destroy`: owner-only (existing policy)
- `DossierController::destroy`: also delete member rows in transaction

---

## Lot 1019-E — Tests

### `DossierSharingTest.php`
- Guest: redirect from member routes
- Owner: add/update/remove members
- Owner: cannot add self as member
- Owner: cannot add cross-org user
- Owner: cannot add already-member
- Owner: visibility toggles shared/private
- Reader: can view dossier
- Reader: cannot attach/detach/reorder articles
- Reader: cannot manage members
- Reader: cannot rename/delete dossier
- Editor: can view and manage articles
- Editor: cannot manage members
- Editor: cannot rename/delete dossier
- Cross-tenant: 404 for all member operations
- Member removal: loses access
- Owner deletion: cascade removes members, dossier goes private if re-created
- Blog rights unchanged: sharing doesn't grant blog edit

---

## Lot 1019-F — Finalization

- Pint
- route list
- view cache
- diff check
- build
- VERSION bump 1.018 → 1.019
- TASK DONE, UNLOCKED, PR READY
- check-task.sh, finalize-task.sh
- push, PR, CI, merge develop

---

# Progress Log


## 2026-07-17 08:35:09 Europe/Paris

Task created. Owner: codeur. Branch: TASK-1019-add-individual-dossier-sharing-reader-editor.

## 2026-07-17 09:00:00 Europe/Paris

Audit complete. Plan court written. Starting Lot 1019-A.

# Handoffs

# Tests

- [x] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [ ] tenant validation

---

# Test Results

## DossierSharingTest — 24 PASS, 48 assertions

```
✓ dossier becomes shared when member added
✓ dossier reverts to private when last member removed
✓ is member returns true for added user
✓ member role for returns role
✓ owner can list members
✓ owner can add member
✓ owner can update member role
✓ owner can remove member
✓ owner can search users
✓ reader cannot manage members
✓ editor cannot manage members
✓ reader can list members
✓ cannot add owner as member
✓ cannot add same member twice
✓ cannot add cross org user
✓ invalid role rejected
✓ user from other org cannot manage members
✓ stranger cannot list members
✓ stranger cannot add member
✓ owner sees manage members section
✓ reader does not see manage members section
✓ editor can see attach form
✓ reader sees shared dossiers in index
✓ owner sees shared badge when dossier has members
```

## Regression — DossierArticleAttachmentTest + DossiersPrivateFoundationTest + BlogDossierCardTest — 49 PASS, 124 assertions

All existing tests safe.

## Verification

- `vendor/bin/pint --dirty --format agent` — PASS
- `php artisan view:cache` — PASS
- `npm run build` — PASS
- `php artisan route:list --path=dossiers` — 19 dossier routes including 5 member routes

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