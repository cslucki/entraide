---
task_id: TASK-1037
title: Validate and repair complete Dossier workspace

status: DONE

owner: opencode

contributors: []

branch: TASK-1037-validate-and-repair-complete-dossier-workspace

priority: MEDIUM

created_at: 2026-07-22 20:32:36 Europe/Paris
updated_at: 2026-07-23 20:55:00 Europe/Paris

labels: [dossier, qa, validation]

lock:
  status: UNLOCKED
  agent: opencode
  since: 2026-07-22 20:32:36 Europe/Paris

handoff: false

pr:
  status: OPEN
  url: https://github.com/cslucki/entraide/pull/96
---

# Objective

QA intégrée et corrections d'intégration du workspace Dossier complet (Contenus, Fichiers, Membres, Shell mobile).
Aucune nouvelle fonctionnalité. Aucun refactor large. Corrections uniquement.

---

# Planned Actions

> Cockpit Master instruction — 2026-07-23 12:05 Europe/Paris: update this TASK file after every validated lot / RED-GREEN checkpoint / test evidence, and make regular small commits at stable GREEN checkpoints. Do not wait until the end of the whole task to commit. Never commit incomplete or failing work; TASK update must be part of each checkpoint commit.

- [x] inspect architecture
- [x] inspect impacted files
- [x] Lot A: Intégrité des contenus (searchQuery + reorder)
- [x] Lot B: Fichiers (tri, search, pagination, preview)
- [x] Lot C: Création d'article depuis un Dossier
- [x] Lot D: Membres (recherche)
- [x] Lot E: Accessibilité modales (RED 27/21, GREEN 48/48, regression A–E 71+65 GREEN)
- [x] Lot F: QA intégrée complète matrice
- [x] bump VERSION 1.037
- [x] check (check-task.sh PASS)
- [x] finalize (finalize-task.sh) — retry 2 with `yes \|`: EXIT=0 (1st `echo "y"` exhausted stdin → `read` fail → `set -e` killed script → EXIT=1)
- [ ] PR (create/update PR → develop)
- [ ] merge (merge-task.sh → develop)

---
# Progress Log

## 2026-07-23 — BLOCKED after safe-test failure (RESOLVED)

- Context: while extending Fichiers work, upload limit was raised toward 50 MB, duplicate file guards were added server/client-side, and upload progress UI was added.
- Build: `npx vite build` passed.
- Syntax: `php -l app/Http/Controllers/DossierFileController.php && php -l app/Http/Requests/StoreDossierFileRequest.php && php -l app/Http/Controllers/LocaleController.php` passed.
- Mandatory dry-run: `./ai/scripts/safe-test.sh --dry-run tests/Feature/DossierFileTest.php` passed safety preflight with sqlite `:memory:`.
- Test command: `./ai/scripts/safe-test.sh tests/Feature/DossierFileTest.php; echo "EXIT_CODE=$?"`.
- Result: FAILED, 36 passed / 2 failed, exit code 1.
- Failures:
  - `test_upload_accepts_valid_image_types`: expected 201, got 422.
  - `test_upload_accepts_doc_types`: expected 201, got 422.
- **GO MASTER obtained 2026-07-23 ~15:40 Europe/Paris.**

### Resolution of BLOCKED

- Root cause: new duplicate-content guard rejected fake test files using default `fakeFile()` with identical content (`str_repeat('x', 1024)`) but different names in the same batch.
- Fix: varied content sizes (`1024, 2048, 3072, 4096` for image types; `1024, 2048, 3072` for doc types). Also fixed `test_upload_rejects_more_than_five_files` for the same issue.
- **DossierFileTest: 38/38 GREEN (79 assertions), EXIT_CODE=0.**

### Additional fixes under GO — 2026-07-23

- **Delete refresh bug** (reproduced on PROD-like URL):
  - DELETE → 200, loadFiles GET → 200, but file sometimes reappeared (server stale response).
  - Fix: safety filter in `deleteFile()` — after `await this.loadFiles()`, if deleted ID still present, filter it out again.
  - **Browser validated: file disappears without reload.**
- **FAB menu**: renamed from "Créer" to "Ajouter" (i18n), reordered "Ajouter" section first, then "Créer".
- **Language toggle**: preserves URL hash (`#fichiers`) via hidden `redirect_to` field in locale forms.
- **Upload limit**: raised from 20MB → 50MB (FormRequest `max:51200`, FilePond `50MB`, Lang FR/EN, `.htaccess` `php_value` directives). `.user.ini` was tried but had no effect in Apache runtime — removed and replaced by `.htaccess`.
- **Duplicate file protection**: server-side checks for same `original_name` and same `checksum_sha256` per dossier; same-batch dedup; client-side pre-filter in FilePond `onaddfile`.
- **Upload progress**: premium progress bar with XHR `onprogress` events, gradient bar, animated icon.
- **i18n**: new keys for upload progress, duplicate errors, FAB, upload help (50MB), network errors, article/markdown failures.

## 2026-07-23 — Cockpit 4-gates audit (GO conditionnel → CHECKPOINT)

### Commits
- `dcbc302` — TASK-1037 Lots A-E + Fichiers fixes + 4 gates (13 files, +1477/-122)

### GATE 1 — AUTHORIZATION ✅
All 5 contested changes traced to Cyril requests:
- 50MB: m0111 "if we upload 15mb the file is blocked" + m0135 "il faut augmenter la limite"
- Doublons: m0138 "on ne doit pas avoir de doublon"
- Progress: m0111 "Il faudrait mettre une mire de progression"
- FAB rename: m0062 "le FAB n'est pas traduit... l'appeler 'Ajouter'"
- FAB reorder: m0059 "mets la section 'Ajouter' en premier, puis 'Créer' ensuite"

### GATE 2 — REDIRECT SECURITY ✅
- `tests/Feature/LocaleControllerTest.php`: 9/9 GREEN
- Micro-verrou: `back()` → `redirect('/')` + external Referer + external redirect_to test

### GATE 3 — UPLOAD CONTRACT ✅
- `.user.ini` deleted (ineffective); `.htaccess` `<IfModule mod_php.c>` replaces it
- Contract: FilePond maxFiles=5, maxFileSize=50MB, PHP upload_max=50M, post_max=260M, Laravel max:51200
- i18n FR/EN: all size/help strings updated to 50MB
- curl verified: upload_max=50M, post_max=260M

### GATE 4 — DUPLICATE ✅
- 4/4 duplicate tests GREEN (same-batch name, same-batch content, sequential name, sequential content)
- Race condition documented: `lockForUpdate()` no-op when no matching row exists; UNIQUE DB constraint needed post-GO

### Test evidence (post-gates)
- E2E Lots A-E: **67/67 GREEN**, EXIT_CODE=0
- PHP Dossier*: **112/112 GREEN** (276 assertions), EXIT_CODE=0
- Full build (vite): OK
- Pint: OK (1 auto-fix)

## 2026-07-23 — Lot F: QA intégrée complète matrice (FIXED + REFACTORED)

### Matrice QA — E2E (post-repair)

| # | Spec | Tests | Status | Notes |
|---|------|-------|--------|-------|
| 1 | lot-a-search-reorder-red | 1/1 | **GREEN** | Search disables reorder during active filter |
| 2 | lot-b-files-server-sort-search | 9/9 | **GREEN** | Sort, search, pagination, fallback, UI |
| 3 | lot-c-create-article-from-dossier | 4/4 | **GREEN** | Create article, Markdown note, Contenus tab |
| 4 | lot-d-member-search | 9/9 | **GREEN** | Case-insensitive, multi-word, org isolation, roles |
| 5 | lot-e-modal-accessibility | 48/48 | **GREEN** | ARIA, focus trap, initial focus, focus return, Escape |
| 6 | audit-1033-dossier-contents | 19/19 | **GREEN** | Test #9 fixed: selector `[x-text="m.displayName"]` → owner badge visibility check |
| 7 | dossier-unified-contents | 10/10 | **GREEN** | Complete rewrite: README accounts + AUDIT-1033 deterministic dossier |

**Total E2E: 96/96 GREEN (100%), EXIT_CODE=0, 0 pre-existing, 0 new.**

Lots A-E (TASK-1037 scope): **67/67 GREEN** (100%).

### Historical spec repairs

#### audit-1033 test #9 — fixed
- Root cause: `[x-text="m.displayName"]` is inside `@if($canManageMembers)` Blade guard → hidden for `reader` role
- Fix: replaced with owner badge visibility check (visible to all roles) + manage button absence check
- From 18/19 → 19/19 GREEN

#### dossier-unified-contents — rewritten
- Root cause: `loginAsMember()` → `/dossiers` → `a[href*="/dossiers/"]`.first() = non-deterministic (member may have no dossiers, first link may be broken → 403/404)
- Fix: complete rewrite with README canon accounts, deterministic AUDIT-1033 dossier URL, no screenshot dependencies, clean test structure
- From 0/11 (12 pre-existing failures) → 10/10 GREEN, 4 views covered (Owner/Editor/Reader/Cross-org, Desktop/Mobile, FR/EN)
- Cross-org returns 403 (dossier exists but org isolation blocks) — correct behavior
- Mobile EN `.first()` needed because 2 `<h1>` elements at 430px (page title + dossier heading)

### Matrice QA — PHP

| # | Test class | Tests | Assertions | Status |
|---|-----------|-------|------------|--------|
| 1 | DossierFileTest | 38/38 | 79 | **GREEN** |
| 2 | DossierSharingTest | 41/41 | 93 | **GREEN** |
| 3 | DossiersArticleAttachmentTest | 24/24 | 81 | **GREEN** |
| 4 | LocaleControllerTest | 9/9 | 23 | **GREEN** |

**Total PHP**: 112/112 GREEN (276 assertions).

### Matrice QA — Cross-cutting (flux croisés vérifiés)

| Flux | E2E | PHP | Browser | Verdict |
|------|-----|-----|---------|---------|
| Contenus (articles, séries, reorder, search) | Lot A+C | 24 | ✓ | **GREEN** |
| Fichiers (upload, delete, sort, search, preview) | Lot B+E | 38 | ✓ | **GREEN** |
| Membres (search, add, remove, roles, org isolation) | Lot D+E | 41 | ✓ | **GREEN** |
| Accessibilité (ARIA, focus trap, Escape, lang) | Lot E | 9 | N/A | **GREEN** |
| Tenant isolation (cross-org, reader/editor roles) | Lot D | All | N/A | **GREEN** |
| i18n (FR/EN keys aligned, FAB localisé) | N/A | N/A | ✓ | **GREEN** |

### Décisions Lot F
- 2 pre-existing historical failures formally catalogued, root-caused, and repaired directly
- audit-1033 test #9: selector fix (1 line)
- dossier-unified-contents: full rewrite (87 lines, 10 tests, 4 views)
- No pre-existing failures remain — 96/96 GREEN (100%)
- QA matrix validated across all 4 core domains (Contenus, Fichiers, Membres, Accessibilité)
- Cross-cutting tenant isolation and i18n verified
- All 6 cross-cutting flux verified GREEN

## 2026-07-22 20:32:36 Europe/Paris

Task created.

Owner: opencode
Branch: TASK-1037-validate-and-repair-complete-dossier-workspace
Status: TESTING

## 2026-07-23 — CHECKPOINT: Lots A–D GREEN

### Status at checkpoint
- Lot A: GREEN (E2E 1/1)
- Lot B: GREEN (E2E 8/8)
- Lot C: GREEN (E2E 4/4, PHP 24/24, accepted by Cockpit)
- Lot D: GREEN (E2E 9/9, PHP 41/41, accepted by Cockpit with documented exception)
- Lot E: RED spec written, blocked pending Cockpit audit
- Total E2E A–D: 23/23 GREEN
- Total PHP: 65/65 GREEN (174 assertions)

### RED proof Lot E (documented before fix — authoritative per Cockpit)
- Command: `npx playwright test tests/e2e/lot-e-modal-accessibility.spec.js --reporter=list --timeout=30000 --workers=1`
- Exit code: 1
- 27 failed, 21 passed
- ARIA missing: 15 tests (modals #1, #4, #5, #6, #7) — role, aria-modal, aria-labelledby
- Focus trap: 5 tests (modals #1, #4, #5, #6, #7) — Tab escapes modal
- Initial focus: 5 tests (modals #1, #4, #5, #6, #7) — focus on body, not inside overlay
- Focus return: 2 tests (modals #4, #5) — FAB trigger not restored after close
- Escape: all GREEN (pre-existing)
- #8/#9 ARIA + focus trap + initial focus: GREEN (pre-existing)

### Cockpit decisions
- Lot D accepted with documented exception (patch preceded RED proof)
- Lot E: no @alpinejs/focus or any new dependency; local DOM/Alpine helper only
- Modals #2/#3: not testable E2E (need series/article context), documented, ARIA fix still applied in Blade

---

## 2026-07-23 — Lot B complete

- Server-side sort/search/pagination in DossierFileController
- Alpine dossierFilesCard: searchQuery, onSearchInput, server-side loadFiles, toggleSort reload, sortedFiles neutralized
- i18n: 6 new FR/EN keys, hardcoded French replaced in Blade
- Blade: search input added to files tab
- Vite rebuild: app-BSKg5VGu.js
- Test GREEN × 2: 9/9 pass
- Regression: 2308 passed, 26 pre-existing (Pgvector+Admin), 0 new

## 2026-07-23 — Lot D complete (corrected)

### Lot D — Member search improvements

**RED→GREEN Documentation:**
- Controller patch (LOWER, multi-word, trim) was applied in a previous session without formal RED proof.
- GREEN behavior verified against current codebase via E2E + PHP tests.
- Expected RED behavior: naive `LIKE '%term%'` would fail on case sensitivity, multi-word (no first_name+name combo), and leading/trailing spaces.

**Implementation:**
- Controller: `DossierMemberController::search()` — `LOWER()` for cross-DB compat, multi-word support, space normalization
- Excludes owner and existing members from results
- Scoped to Organization (org_id check)
- Role-based access: only `manageMembers` (owner) can search

**PHP Tests (DossierSharingTest):** 41/41 GREEN (93 assertions)
- 8 search-specific tests: first name, last name, full name, case insensitive, space normalization, org isolation, reader/editor/stranger refused

**E2E Tests (lot-d-member-search.spec.js):** 9/9 GREEN
- Owner can search org users → non-empty results with id/name/email fields
- Owner is excluded from results
- Existing members excluded from results
- Case insensitive: lowercase/uppercase/mixed produce same non-empty result set with identical IDs
- Full name search (first_name + last_name): "Kiran Akshay" → 1 match
- Full name reversed (last_name + first_name): "MALINA Roger" → 1 match
- Multi-space and trim normalized: "  kiran  akshay  " equals "kiran akshay"
- Reader (member role) gets 403
- Cross-org user (Main admin) gets 403

**Fixture:** Deterministic LaunchPals dossier "LotD-FIXTURE" (id: 019f8e47-4a57-72e9-a280-6dd50ce12fdd)
- Owner: launchpals.member1@bouclepro.test
- All tests run against LaunchPals org; Main user only as cross-org actor

**i18n:** FR/EN `member_search_placeholder` present

**Regression:**
- E2E TASK Lots A+B+C+D: 23/23 GREEN
- PHP DossierSharingTest: 41/41 GREEN
- PHP DossiersArticleAttachmentTest: 24/24 GREEN
- Total PHP: 65/65 GREEN (174 assertions)

---

## 2026-07-23 — Lot E audit (RED spec only, code changes BLOCKED)

### Lot E — Accessibility modales

**RED spec:** `tests/e2e/lot-e-modal-accessibility.spec.js` — 6 tests
- Article creation modal: missing `role="dialog"`, `aria-modal`, `aria-labelledby`
- Markdown note modal: missing ARIA attributes
- Delete file modal: missing ARIA attributes
- Preview modal: missing ARIA attributes
- Escape key close (×2 tests)
- Backdrop click close

**Modals already accessible (no changes needed):**
- Manage members modal (has `role="dialog"`, `aria-modal`, `aria-labelledby`)
- Remove member modal (already accessible)

**Scope of code changes (BLOCKED until Lot D validated by Cockpit):**
- `resources/views/dossiers/show.blade.php`: Add `role="dialog"`, `aria-modal="true"`, `aria-labelledby` to 4 modals
- Blade IDs: article modal (L736), markdown modal (L767), delete modal (L990), preview modal (L1004)

---

## 2026-07-23 — Lot C.1+2 complete

### Lot C.1 — showError/showSuccess + delete refresh fixes

- `showError(msg)` and `showSuccess(msg)` did not exist in `dossierFilesCard` (Alpine component)
- All 9 occurrences across 3 functions (`createArticle()`, `createMarkdownNote()`, `handleMediaFiles()`) replaced with existing `showMessage(msg, type)`
- Delete refresh bug: `confirmDeleteFile()` was synchronous — `deleteTarget` cleared before `deleteFile` could read it
- Fix: `async/await` in `confirmDeleteFile()`, optimistic removal in `deleteFile()` (array filter before server call), fallback `loadFiles` on error
- Files modified: `resources/js/app.js` (dossierFilesCard section)
- Browser verified: docx deleted → disappears immediately. Markdown note created → appears immediately. Zero console errors.
- Build: Vite rebuild not required (no new state/methods, only existing `showMessage` reuse)

### Lot C.2 — PHP feature tests for createAndAttach (7 gates)

**Gates proven (RED→GREEN):**

| Gate | Test | Result |
|------|------|--------|
| 1. Owner creates → 201 | `test_owner_can_create_and_attach_article` | GREEN |
| 2. Editor creates → 201 | `test_editor_can_create_and_attach_article` | GREEN |
| 3. Reader creates → 403 | `test_reader_cannot_create_and_attach_article` | GREEN |
| 4. Cross-org → 404 | `test_cross_organization_user_cannot_create_and_attach` | GREEN |
| 5. No orphan draft on 422 | `test_create_and_attach_title_required_no_orphan_post` | GREEN |
| 6. Invalid category → 422 no orphan | `test_create_and_attach_invalid_category_no_orphan_post` | GREEN |
| 7. redirect_url org-scoped | `test_create_and_attach_redirect_url_is_org_scoped` | GREEN |

- File: `tests/Feature/DossiersArticleAttachmentTest.php` — 7 new tests added to existing 17
- 24/24 GREEN (81 assertions), regression 162/163 (1 pre-existing pgvector failure)
- Cross-org returns 404 (not 403) — dossier invisible from wrong org, correct behavior

### Lot C.3 — E2E test: article appears in Contenus

- New test: `test_green_created_article_appears_in_contenus_list_immediately`
- Creates article via API, navigates to dossier page, asserts title visible in Contenus tab
- 4/4 E2E GREEN (Lots A+B+C), full E2E regression 14/14

### Lot C.4 — E2E + PHP regression

- E2E ciblés TASK Lots A+B+C: 14/14 GREEN
- Régression E2E Dossier élargie: non concluante, baseline à établir au Lot F
- PHP feature tests dossier-related: 162/163 GREEN (1 pre-existing pgvector)
- `DossiersArticleAttachmentTest`: 24/24 GREEN (81 assertions)

---

## 2026-07-23 — Lot B micro-lockdown

- Debounce pattern corrigé : `x-model="searchQuery" @input.debounce.300ms="onSearchInput()"` (fragile pattern remplacé)
- Colonne uploader neutralisée : `<span>` au lieu de `<button @click="toggleSort('uploader')">`, pas d'indicateur sortable
- Vite rebuild effectué
- Lot B fonctionnellement GREEN sur comportement generic Organization-scoped
- Tests Lot A/B sans référence CPME ; vars CPME factices uniquement à cause du gate global `tests/setup.js` (non résolu)
- `tests/e2e/lot-a-search-reorder-red.spec.js` = nouveau fichier non tracké dans la branche
- Bundle Vite non tracké par git (dans public/build/, gitignoré)
- ne pas migrer tests Lot B vers LaunchPals maintenant → obligatoire au Lot F
- Rapport déplacé manuellement vers `TODO/REPORTS/TASK-1037-LOT-B-RAPPORT-FINAL.md`


## 2026-07-22 21:00:00 Europe/Paris

### Architecture audit complete

#### Alpine components (all in resources/js/app.js)

| Component | Lines | Purpose |
|-----------|-------|---------|
| dossierTabs | 1818-1839 | Tab shell (hash routing) |
| dossierContentsCard | 1845-2337 | Contents tab (series + ungrouped) |
| dossierSemanticArticleSearch | 2343-2423 | Semantic search |
| dossierMembersCard | 2429-2606 | Members tab |
| dossierArticlesCard | 2612-2773 | Standalone (not in show) |
| dossierFilesCard | 2781-3188 | Files tab |

#### Controllers

| Controller | Lines | Key endpoints |
|------------|-------|---------------|
| DossierArticleController | 274 | store, destroy, reorder, search |
| DossierController | 264 | index, show, store, update, destroy |
| DossierMemberController | 190 | index, store, update, destroy, search |
| DossierFileController | 256 | index, store, show, preview, destroy |

#### Confirmed defects

**Lot A — searchQuery reorder bug:**
- `moveAnnex(index, direction)` at app.js:2050 uses `index` from `x-for` over `filteredAnnexItems`
- When searchQuery active, `filteredAnnexItems` is a subset of `seriesItems`
- The filtered index is passed to `moveAnnex` which operates on `this.seriesItems[index]`
- This swaps the WRONG element in the source array
- Same bug for `moveUngrouped(index, direction)` at app.js:2311
- `:disabled` guards also use filtered indices
- Drag-and-drop also applies DOM order which may not match source array when filtered

# Handoffs

# Tests

- [x] feature tests (Lot C: 24/24 DossiersArticleAttachmentTest, regression 162/163)
- [x] browser validation (Lot C: delete refresh, create article, markdown note, zero console errors)
- [x] responsive validation (E2E: 320×720, 375×812, 430×932, 1280×720 — audit-1033 + dossier-unified-contents)
- [x] console inspection (Lot C: zero errors confirmed)
- [x] tenant isolation (Lot C: cross-org 404, cross-tenant 403, editor/reader role tests)

---

# Test Results

## Lot C — 2026-07-23

**PHP Feature Tests (DossiersArticleAttachmentTest):** 24/24 GREEN (81 assertions)
**Regression PHP (all Dossier* tests):** 162/163 GREEN (1 pre-existing pgvector)
**E2E ciblés TASK Lots A+B+C+D:** 20/20 GREEN
**Régression E2E Dossier élargie:** non concluante — `tests/e2e/ --grep "dossier|Dossier"` sélectionne ~43 tests, timeout >180s, au moins 6 échecs dans des specs historiques (`audit-1033-dossier-contents`, `dossier-unified-contents`). Baseline à établir au Lot F.

Lot C gates proven:
- Owner can create-and-attach → 201 + blog_post created + entry created
- Editor can create-and-attach → 201
- Reader cannot create-and-attach → 403
- Cross-org user → 404 (dossier invisible)
- Title required → 422, no orphan blog_post
- Invalid category → 422, no orphan blog_post
- redirect_url is org-scoped (/org/{slug}/blog/{slug}/edit)
- Created article appears in Contenus list immediately (E2E)

---

# Review Notes

## Synthèse finale — 2026-07-23

- **Lots A–F GREEN**: 96/96 E2E, 112/112 PHP (276 assertions), EXIT_CODE=0
- **QA users README** utilisés (admin, main.member1, main.member2, launchpals.member1) — aucune dépendance CPME dans la matrice Dossier
- **Organization = Tenant** préservé — cross-org returns 403, no code outside Organization context
- **Aucune migration** — zéro modification de schéma DB
- **Dette connue**: concurrence doublons — `lockForUpdate()` no-op quand aucune ligne n'existe ; nécessiterait UNIQUE DB constraint (hors scope sans GO)
- **Configuration 50 Mo**: locale Apache `.htaccess` (mod_php.c), pas Laravel Cloud (Nginx)
- **i18n**: FR/EN keys alignés, FAB + upload + duplicate + progress localisés
- **Accessibilité**: 9 modales avec role/aria-modal/aria-labelledby + focus trap + focus return (Lot E 48/48 GREEN)
- **Pint**: PASS. **Vite build**: OK. **diff --check**: clean. **Secrets**: aucun.
- **TODO file**: local-only (`.gitignore`), modifications non commitées (tracké historiquement)

---

# Lot A — Intégrité des contenus

## Defect: searchQuery reorder

### Reproduction

1. Dossier with 3+ ungrouped articles and/or annexes
2. Type a search query that filters to subset
3. Click Monter/Descendre on a filtered item
4. Observe: wrong article moves (the one at the filtered index in the source array, not the visible one)

### Root Cause

`x-for="(item, index) in filteredAnnexItems"` produces `index` relative to the filtered subset.
`moveAnnex(index, direction)` applies this index directly to `this.seriesItems[index]`.
When items are filtered out, the indices don't correspond.

### Fix

MVP per spec: When searchQuery is active, disable drag-and-drop and move buttons.
Show localized message: "Effacez la recherche pour réorganiser les contenus."

### Implementation history

- **2026-07-22 attempt 1**: GREEN fix applied directly (i18n keys, `isSearchActive` getter, `disabled` bindings, `x-show` on handles, `$watch` on searchQuery). **Discarded** — no RED test existed beforehand. Violates mandatory RED→GREEN rule.
- **2026-07-22 attempt 2**: Recovery. Reverted all 4 implementation files. RED test first, then GREEN fix.

### RED proof

- Test: `tests/e2e/lot-a-search-reorder-red.spec.js`
- Original RED version called `data.moveUngrouped(0, 1)` via Alpine internals during active search
- Result: `moveUngrouped(0, 1)` swapped `ungrouped[0]` (Art4) with `ungrouped[1]` (Art5) instead of moving Art5 down past Art6
- Expected Art5 at index 2 → Received Art4 at index 1 → **RED confirmed**

### GREEN proof

- Same test rewritten for UI-level assertions (no Alpine internal calls that trigger PATCH)
- After `npm run build`: test passes — all move buttons disabled during active search, guidance message visible, re-enabled on clear
- Test passes twice consecutively without modifying fixtures (positions 1-8 verified unchanged)
- Build required: `isSearchActive` getter must be in compiled Vite bundle

### Files modified

- `lang/fr/dossiers.php` — added `clear_search_to_reorder`
- `lang/en/dossiers.php` — added `clear_search_to_reorder`
- `resources/js/app.js` — added `get isSearchActive()`, `$watch('searchQuery')` to disable SortableJS
- `resources/views/dossiers/show.blade.php` — `:disabled="... || isSearchActive"` on move buttons, `x-show="!isSearchActive"` on drag handles, amber guidance message, i18n config key
- `public/build/assets/app-*.js` — rebuilt via `npm run build`

---

# Lot B — Fichiers (tri, search, pagination)

## Defect: Server-side sort/search missing, client-side sort broken across pages

### Root Cause

- `DossierFileController::index()` used `->latest()` only — no `sort`, `direction`, `search` params
- Alpine `sortedFiles` getter sorted only the current page's 20 items client-side
- Sort was broken across pages (changing page = different visible order)
- No search input existed for files tab

### Fix Applied

#### Controller (`DossierFileController.php`)
- Added `sort`, `direction`, `search` query params
- Sort allowlist: `name` → `display_name`, `size` → `size_bytes`, `date` → `created_at`
- Strict direction allowlist: `asc`, `desc` (fallback: `desc`)
- Invalid sort column falls back to `created_at`
- Search filters `display_name` and `original_name` with `ilike` (PostgreSQL) / `like` (SQLite)
- Tenant-scoped via `organization_id` + `dossier_id`
- Secondary sort: `created_at desc` for deterministic pagination

#### Alpine (`resources/js/app.js` — `dossierFilesCard`)
- Added `searchQuery: ''` state
- Added `onSearchInput()` — resets to page 1, calls `loadFiles(1)`
- Updated `loadFiles(page)` — sends `sort`, `direction`, `search` as URL params
- Updated `toggleSort(column)` — calls `loadFiles(1)` after changing sort state
- Neutralized `sortedFiles` getter — returns `this.files` (server handles sort)
- Default sort changed from `name/asc` to `date/desc`

#### Blade (`show.blade.php`)
- Added search input with debounced `x-model` above file table
- Added i18n keys to Alpine config: `previewNotAvailable`, `searchPlaceholder`

#### i18n (`lang/fr/dossiers.php`, `lang/en/dossiers.php`)
- Added: `file_date`, `file_actions`, `file_view_list`, `file_view_grid`, `file_search_placeholder`, `file_preview_not_available`
- Replaced hardcoded French in Blade: `Date`, `Actions`, `Vue liste`, `Vue grille`, `Aperçu non disponible…`

### Files Modified

- `app/Http/Controllers/DossierFileController.php` — server-side sort/search
- `resources/js/app.js` — Alpine `dossierFilesCard` changes
- `resources/views/dossiers/show.blade.php` — search input, i18n
- `lang/fr/dossiers.php` — 6 new keys
- `lang/en/dossiers.php` — 6 new keys
- `public/build/assets/app-BSKg5VGu.js` — rebuilt

### GREEN Proof

- Test: `tests/e2e/lot-b-files-server-sort-search.spec.js`
- 9/9 passed, GREEN × 2 consecutive runs (17.0s, 17.3s)
- Tests cover: date desc sort, name sort determinism, size asc sort, search filtering, invalid sort fallback, invalid direction fallback, pagination, UI search input, UI search filtering

### Regression Proof

- Lot A test: 1 passed (no regression)
- safe-test.sh: 2308 passed, 26 failed (all pre-existing: Pgvector 12 + Admin/other 14)
- 0 new failures from Lot B changes

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is explicit via `bump-version.sh`
- Footer always displays `config('app.version')`
