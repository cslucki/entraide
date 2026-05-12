---
task_id: TASK-062
title: search-portability-like-ilike

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-062-search-portability-like-ilike

priority: MEDIUM

created_at: 2026-05-12 16:07:33 Europe/Paris
updated_at: 2026-05-12 17:18:00 Europe/Paris

labels:
  - search
  - postgresql
  - portability
  - like
  - ilike

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: 2026-05-12 16:07:33 Europe/Paris

handoff: false

pr:
  status: READY
  url: null
---

# Objective

Fix PostgreSQL search portability by replacing LIKE with ILIKE on PostgreSQL for case-insensitive matching, while preserving SQLite compatibility.

---

# Audit Findings

All LIKE queries are in a single file:
- `app/Http/Controllers/SearchController.php`

8 total LIKE usages across 4 entity types:
1. Service (title, description) — 2 LIKE
2. ServiceRequest (title, description) — 2 LIKE
3. User (name, location) — 2 LIKE
4. BlogPost (title, content, tags.name, categories.name) — 4 LIKE

No LIKE usage found in model scopes (scopeActive, scopePublished, scopeOpen — none use LIKE).
No other search service classes extracted — all logic is inline in the controller.

---

# Solution

Minimal, driver-aware approach:
- Detect DB driver at runtime: `DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like'`
- Use `$likeOperator` variable in all 8 `where()` calls
- Zero architectural impact, no new abstractions, no global rewrites

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [x] validate UI (no UI changes)

---

# Progress Log

## 2026-05-12 16:07:33 Europe/Paris

Task created.
Owner: OPENCODE
Branch: TASK-062-search-portability-like-ilike
Status: IN_PROGRESS

## 2026-05-12 17:15:00 Europe/Paris

Audited SearchController and related models.
All 8 LIKE queries are in SearchController.php.
No LIKE usage in model scopes.

## 2026-05-12 17:18:00 Europe/Paris

Implemented fix in SearchController.php:
- Added `use Illuminate\Support\Facades\DB`
- Added `$likeOperator` variable with driver detection
- Replaced all 8 `'like'` operators with `$likeOperator`

Test results:
- SQLite: 294/294 ✅ (9 search tests pass)
- PostgreSQL: 294/294 ✅ (9 search tests pass, including 2 previously failing)

The 2 previously failing tests now pass on PostgreSQL:
- test_search_excludes_inactive_services (LIKE '%vidéo%' now matches 'Vidéo montage' via ILIKE)
- test_search_finds_open_service_requests (LIKE '%cherche%' now matches 'Cherche photographe' via ILIKE)

# Handoffs

## 2026-05-12 17:18:00 Europe/Paris

### Current State
- TASK-062: TESTING
- 1 file modified, 0 new files
- 294/294 SQLite ✅ | 294/294 PostgreSQL ✅

### Modified Files
1. `app/Http/Controllers/SearchController.php` — added DB facade import, driver-aware LIKE/ILIKE operator

### Pending Actions
1. Create PR
2. Merge after review
3. Unblock TASK-061

### Known Risks
- None expected. Change is minimal, well-understood, and validated on both engines.

# Tests

- [x] feature tests (294 SQLite ✅, 294 PostgreSQL ✅)
- [x] browser validation (not needed — no UI changes)
- [ ] responsive validation (not needed — no UI changes)
- [ ] console inspection (not needed — no UI changes)
- [ ] tenant validation (no scope changes)

---

# Test Results

2026-05-12 17:18:00 Europe/Paris

| Engine    | Tests | Assertions | Pass | Fail |
|-----------|-------|------------|------|------|
| SQLite    | 294   | 597        | 294  | 0    |
| PostgreSQL| 294   | 597        | 294  | 0    |

---

# Review Notes

No architecture drift introduced.
No Organization changes.
No Playwright regressions expected (no UI changes).
Search behavior preserved on SQLite, fixed on PostgreSQL.

---

---

# Review Notes

Pending.