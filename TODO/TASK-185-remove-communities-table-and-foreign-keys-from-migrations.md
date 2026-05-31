---
task_id: TASK-185
title: remove communities table and foreign keys from migrations

status: DONE

owner: SUPERVISOR

contributors: []

branch: TASK-185-remove-communities-table-and-foreign-keys-from-migrations

priority: MEDIUM

created_at: 2026-05-31 16:03:05 Europe/Paris
updated_at: 2026-05-31 16:19:26 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Remove the legacy `communities` table and foreign key targets from the migration layer while preserving legacy `community_id` column names for compatibility.

---

# Planned Actions

- [x] inspect orchestrator order
- [x] verify clean `develop` before task creation
- [x] inspect impacted migrations
- [x] update migration table/FK targets from `communities` to `organizations`
- [x] run migration audit and automated tests
- [x] write supervisor report
- [x] UI validation not applicable; migration-only backend task

---
# Progress Log


## 2026-05-31 16:03:05 Europe/Paris

Task created.

Owner:
SUPERVISOR

Branch:
TASK-185-remove-communities-table-and-foreign-keys-from-migrations

Status:
IN_PROGRESS

## 2026-05-31 16:05:17 Europe/Paris

Verified repository was clean on `develop` and aligned with `origin/develop` before creating this task.

Implemented migration-only changes within the authorized scope:
- initial legacy community migration now creates/drops `organizations`
- legacy `community_id` foreign keys now reference `organizations`
- organization attribute migrations now target `organizations`
- `rename_communities_to_organizations` is conditional for fresh installs and legacy databases

No `community_id` column was removed or renamed. No `add_organization_id_to_*` migration was created. No `app/`, `tests/`, policy, transaction, ledger, messaging, admin, or frontend files were modified.

Status set to DONE and lock released after validations and report update.

## 2026-05-31 16:11:22 Europe/Paris

Addressed VERIFICATOR `CHANGES_REQUESTED` for RUN-005F.

Updated only `database/migrations/2026_05_06_160412_add_meta_fields_to_blog_posts_table.php` so the preserved legacy `community_id` column explicitly references `organizations` via `constrained('organizations')`.

Reran the required explicit and implicit migration audits plus `php artisan test`; all passed. Status remains DONE and lock remains UNLOCKED.

## 2026-05-31 16:19:26 Europe/Paris

VERIFICATOR final verdict: `ACCEPT`.

Final report written to `ai-local/verificator/report-from-verificator/20260531-RUN-005F-VERIFICATOR-FINAL-REPORT.md`.

Validated points:

- Cumulative diff limited to authorized migrations and this TASK.
- No active `Schema::create('communities')`, `Schema::table('communities')`, or FK `on('communities')` remains.
- No implicit `community_id->constrained()` FK remains.
- No `community_id` column was removed or renamed.
- No new `add_organization_id_to_*` migration was created.

# Handoffs

None. Task completed by SUPERVISOR.

# Tests

- [x] feature tests
- [ ] browser validation (not applicable; migration-only task)
- [ ] responsive validation (not applicable; migration-only task)
- [ ] console inspection (not applicable; migration-only task)
- [x] tenant validation via migration audit and existing tenant test suite

---

# Test Results

- `rg -n "Schema::create\('communities'\)|Schema::table\('communities'\)|on\('communities'\)|create_communities|add_.*_to_communities" database/migrations` — PASS, no output.
- `rg -n "foreignUuid\('community_id'\).*constrained\(\)|foreignId\('community_id'\).*constrained\(\)" database/migrations` — PASS, no output.
- `php artisan migrate:fresh --seed --env=testing` — BLOCKED by local PostgreSQL connection refused on `127.0.0.1:5432`; scope not expanded.
- `php artisan test` — PASS, 826 passed, 11 skipped, 1756 assertions. Rerun after VERIFICATOR correction: PASS, 826 passed, 11 skipped, 1756 assertions.

---

# Review Notes

- Fresh installs no longer create a `communities` table from the initial migration.
- Fresh installs keep legacy `community_id` columns but point their FK constraints at `organizations`.
- VERIFICATOR finding fixed: blog post `community_id` now explicitly targets `organizations` instead of relying on Laravel's implicit `communities` convention.
- VERIFICATOR final verdict: `ACCEPT`.
- Legacy database path remains supported when `communities` exists and `organizations` does not.
- Residual risk: PostgreSQL fresh migration could not be executed locally because the database service was unavailable.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
