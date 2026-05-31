---
task_id: TASK-184
title: rename remaining community variables in seeders

status: DONE

owner: SUPERVISOR

contributors: []

branch: TASK-184-rename-remaining-community-variables-in-seeders

priority: MEDIUM

created_at: 2026-05-31 15:50:53 Europe/Paris
updated_at: 2026-05-31 15:52:20 Europe/Paris

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

Clean the remaining legacy `community` variable/key vocabulary in database seeders only, without migrations, app-code changes, test changes, push, or merge.

---

# Planned Actions

- [x] verify clean `develop` precondition
- [x] create and verify TASK/branch
- [x] inspect impacted seeder files
- [x] rename local `community` variables/keys to `organization` equivalents
- [x] run audit command
- [x] run test suite
- [x] write supervisor report

---
# Progress Log


## 2026-05-31 15:50:53 Europe/Paris

Task created.

Owner:
SUPERVISOR

Branch:
TASK-184-rename-remaining-community-variables-in-seeders

Status:
IN_PROGRESS

## 2026-05-31 15:52:20 Europe/Paris

Verified preconditions: repository was clean on `develop` and aligned with `origin/develop` before task creation.

Created TASK-184 and branch `TASK-184-rename-remaining-community-variables-in-seeders`; verified task ID and branch match.

Inspected the allowed seeders and found remaining matches limited to local variables and QA account array keys.

Implemented behavior-preserving vocabulary renames only in seeders:

- `database/seeders/UserSeeder.php`: `$community` renamed to `$organization`.
- `database/seeders/LegacyDataOrganizationSeeder.php`: `$community` renamed to `$organization`.
- `database/seeders/QaAccountsSeeder.php`: `community_slug` renamed to `organization_slug`; `$communityId` renamed to `$organizationId`.

No migrations, app code, or tests were modified.

Audit command completed with no matches:

```bash
rg -n "\$community|\$communityId|community_slug|CommunitySeeder|\$communities" database/seeders
```

Full Laravel test suite passed:

```bash
php artisan test
```

Result: 826 passed, 11 skipped, 1756 assertions, duration 25.70s.

Supervisor report written to `ai-local/supervisor/report-to-orchestrator/20260531-RUN-005E-SUPERVISOR-REPORT.md`.

Status set to DONE and task unlocked for orchestration review. Push and merge intentionally not performed.

# Handoffs

No handoff. Work completed by SUPERVISOR for RUN-005E.

# Tests

- [x] feature tests
- [ ] browser validation - not applicable; seeder-only backend vocabulary rename
- [ ] responsive validation - not applicable; no UI changes
- [ ] console inspection - not applicable; no frontend changes
- [x] tenant validation - covered by full feature suite including tenant/organization tests

---

# Test Results

2026-05-31 15:52:20 Europe/Paris

- `rg -n "\$community|\$communityId|community_slug|CommunitySeeder|\$communities" database/seeders` passed with no matches.
- `php artisan test` passed: 826 passed, 11 skipped, 1756 assertions, duration 25.70s.

---

# Review Notes

Scope respected: only the three requested seeders, this TASK file, and the required supervisor report were changed.

Risk is low because the code changes are local variable/key renames inside seeders and preserve all model lookups, database column names, IDs, messages, and seeded values.

No migrations, app code, tests, push, or merge were performed.

Modified files:

- `database/seeders/UserSeeder.php`
- `database/seeders/LegacyDataOrganizationSeeder.php`
- `database/seeders/QaAccountsSeeder.php`
- `TODO/TASK-184-rename-remaining-community-variables-in-seeders.md`
- `ai-local/supervisor/report-to-orchestrator/20260531-RUN-005E-SUPERVISOR-REPORT.md`

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
