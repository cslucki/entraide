---
task_id: TASK-248
title: Fix admin AI interaction input_hash fallback

status: DONE

owner: OPENCODE

contributors:
  - CODEUR

branch: TASK-248-fix-admin-ai-interaction-input-hash-fallback

priority: HIGH

created_at: 2026-06-11 15:46:12 Europe/Paris
updated_at: 2026-06-11 16:05:00 Europe/Paris

labels:
  - ai
  - admin-ai
  - persistence
  - bugfix

lock:
  status: UNLOCKED
  agent: null
  since: 2026-06-11 16:05:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Fix the post-TASK-247 verification reserve where `admin_ai_interactions.input_hash`
remains `NULL` when callers provide only `input_excerpt` and no `input_hash` or
raw `content`.

The correction must be narrow: compute a hash from the available excerpt only as
a fallback, without storing the full raw content and without re-hashing a value
already supplied in `input_hash`.

Strict scope confirmed by Cockpit:

- Fix only the `input_hash` fallback calculation.
- Never hash an already supplied `input_hash` value.
- Do not store the full raw content.
- Do not modify DB schema, migrations, `.env`, `config/ai.php`, providers,
  models, or UI.
- Do not touch `/admin/ai-supervision` outside existing tests if needed.

---

# Context

TASK-247 stored admin AI interactions in DB and was merged with an accepted
non-blocking reserve from VERIFICATOR: `input_hash` was still `NULL` because
`LoggingSupervisionProvider` callers pass `input_excerpt`, not raw `content`.

Current known issue in `AdminAiInteractionPersistence::persist()`:

```php
'input_hash' => $this->hashInput($data['input_hash'] ?? ($data['content'] ?? null)),
```

This produces `NULL` when only `input_excerpt` exists. The intended behavior is:

- if `input_hash` is present, persist it as-is;
- otherwise hash `content` if available;
- otherwise hash `input_excerpt` if available;
- otherwise keep `NULL`.

---

# Planned Actions

- [ ] read mandatory coordination docs and this TASK file
- [ ] inspect `AdminAiInteractionPersistence` and existing TASK-247 tests
- [ ] implement minimal fallback without schema/model/provider/UI/config changes
- [ ] add or update focused tests proving non-null hash from `input_excerpt`
- [ ] add or update focused tests proving provided `input_hash` is not re-hashed
- [ ] run PostgreSQL test DB preflight before Laravel tests
- [ ] run targeted tests only
- [ ] verify no migration/config/provider/model/UI files changed
- [ ] update TASK and conversation with report, tests, modified files

---
# Progress Log


## 2026-06-11 15:46:12 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-248-fix-admin-ai-interaction-input-hash-fallback

Status:
IN_PROGRESS

## 2026-06-11 15:46:23 Europe/Paris

ORCH checkpoint after TASK-247 merge: `develop` was clean and aligned with
`origin/develop` before creating TASK-248.

Cockpit confirmed strict scope and authorized creation of:

- branch `TASK-248-fix-admin-ai-interaction-input-hash-fallback`
- this TASK file
- one conversation file in `ai-local/conversations/`
- short SMT to `codeur` after writing the long prompt

ORCH role remains coordination only. CODEUR must implement. VERIFICATOR must
verify read-only after CODEUR DONE.

## 2026-06-11 15:48:00 Europe/Paris

ORCH created conversation file:

`ai-local/conversations/20260611-15h46-TASK-248-input-hash-fallback.md`

Short SMT sent to tmux session `codeur` with strict implementation scope:

- fallback `input_hash` from `input_excerpt` only when needed;
- no DB/schema/migrations changes;
- no `.env`, `config/ai.php`, provider, model, or UI changes;
- DB preflight to `bouclepro_test` before targeted tests;
- CODEUR must update TASK + conversation and reply DONE via SMT.

## 2026-06-11 16:05:00 Europe/Paris

CODEUR implementation DONE report:

### Fix applied

- `AdminAiInteractionPersistence::persist()` line 21:
  - **Before**: `'input_hash' => $this->hashInput($data['input_hash'] ?? ($data['content'] ?? null)),`
  - **After**: `'input_hash' => $data['input_hash'] ?? $this->hashInput($data['content'] ?? ($data['input_excerpt'] ?? null)),`
- This ensures:
  1. A provided `input_hash` is preserved as-is (no re-hashing).
  2. If no `input_hash` is provided, `content` is hashed first.
  3. If no `content` is provided, `input_excerpt` is hashed as fallback.
  4. If nothing is available, `input_hash` remains `NULL`.

### Tests

- Created `tests/Unit/Services/Ai/Persistence/AdminAiInteractionPersistenceTest.php` with 4 focused tests:
  - `test_provided_input_hash_is_preserved_not_re_hashed` — asserts provided hash is stored as-is.
  - `test_input_hash_fallback_uses_input_excerpt_when_content_is_missing` — asserts `input_excerpt` is hashed when `content` absent.
  - `test_input_hash_fallback_uses_content_before_input_excerpt` — asserts `content` takes precedence over `input_excerpt`.
  - `test_input_hash_is_null_when_no_input_available` — asserts `NULL` when no input data.

### Test results

- `AdminAiInteractionPersistenceTest`: **4 passed, 4 assertions, 3.57s**
- `AdminAiSupervisionTest` persistence regression (5 targeted tests): **5 passed, 39 assertions, 2.21s**
- DB preflight: `database.default = pgsql`, `database.connections.pgsql.database = bouclepro_test` — safe.

### Diff check

- Modified: `app/Services/Ai/Persistence/AdminAiInteractionPersistence.php` (1 line)
- Added: `tests/Unit/Services/Ai/Persistence/AdminAiInteractionPersistenceTest.php`
- No migration, schema, `.env`, `config/ai.php`, provider, model, or UI changes.

# Handoffs

# Tests

- [x] DB-safe preflight confirms `pgsql` + `bouclepro_test`
- [x] targeted persistence unit tests (4 focused)
- [x] test proves `input_hash` fallback uses `input_excerpt` when needed
- [x] test proves provided `input_hash` is preserved as-is
- [x] explicit diff check confirms no migration/config/provider/model/UI changes

---

# Test Results

2026-06-11 16:05 Europe/Paris

- `AdminAiInteractionPersistenceTest`: 4 passed, 4 assertions, 3.57s
- `AdminAiSupervisionTest` persistence regression: 5 passed, 39 assertions, 2.21s
- DB preflight: safe (`bouclepro_test`)

---

# Review Notes

- VERIFICATOR must confirm the diff is exactly 1 line in `AdminAiInteractionPersistence` + 1 new test file.
- VERIFICATOR must confirm no migration/schema/config/provider/model/UI changes.
- VERIFICATOR must confirm `input_hash` is preserved as-is when provided.
- VERIFICATOR must confirm `input_excerpt` is hashed only when `input_hash` and `content` are absent.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
