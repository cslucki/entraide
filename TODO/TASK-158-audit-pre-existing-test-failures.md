---
task_id: TASK-158
title: Audit pre-existing test failures after Community migration

status: DONE

owner: ORCHESTRATOR

contributors: []

branch: TASK-158-audit-pre-existing-test-failures

priority: HIGH

created_at: 2026-05-28 19:54:48 Europe/Paris
updated_at: 2026-05-28 20:18:00 Europe/Paris

labels:
  - tests
  - audit
  - community-migration
  - organization

lock:
  status: UNLOCKED
  agent: ORCHESTRATOR
  since: 2026-05-28 20:18:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Audit and categorize the 35 pre-existing test failures reported after the Community to Organization migration.

The goal is not to fix unrelated failures immediately. The goal is to identify whether failures are still pre-existing, migration-related, environment-related, or require extra tests before the next test wave.

---

# Scope

In scope:

- inspect latest test output/logs
- run targeted commands if needed
- categorize failures by cause
- produce a concise audit report

Out of scope:

- changing production code
- changing vocabulary
- creating Code Base documentation

---

# Planned Actions

- [x] inspect existing reports and logs
- [x] run targeted failing test groups if feasible
- [x] categorize failures
- [x] decide whether additional tests are needed before the full test wave
- [x] document findings and unlock task

---

# Progress Log

## 2026-05-28 19:54:48 Europe/Paris

Task created by ORCHESTRATOR in isolated worktree due dirty primary worktree (`TODO/TASK-144...` and `opencode.json` had pre-existing local changes). The official `create-task.sh` is hardcoded to the primary worktree and performs `git checkout -b`, so it was not safe to run without touching unrelated local changes.

Branch:
`TASK-158-audit-pre-existing-test-failures`

Worktree:
`/home/cyril/claude-code/sites/test.laravel-T158`

## 2026-05-28 20:18:00 Europe/Paris

Audit completed. The current PHPUnit suite cannot reach the reported 35 failures because a fatal PHP duplicate import stops admin route/test loading first:

`app/Http/Controllers/Admin/AdminController.php` contains duplicate `use App\Models\Organization;` lines.

`git blame` shows line 9 came from phase 4 commit `5b28975`.

Report created:
`docs/audits/TASK-158-pre-existing-test-failures-audit.md`

Recommendation: do not launch the full test wave yet. Create a tiny fix branch for the duplicate import, then rerun the audit/full test suite to expose the real remaining failure list.

---

# Handoffs

No handoff. Task complete and unlocked.

# Tests

- [x] test log/report inspection
- [x] full PHPUnit attempted
- [x] targeted admin test attempted
- [x] route loading checked

---

# Test Results

- `php artisan test --log-junit storage/logs/task-158-phpunit.xml` stops early with `Premature end of PHP process`.
- Targeted `AdminCategoriesTest::test_guest_cannot_access_admin_categories` stops with same fatal.
- `php artisan route:list --name=admin.categories` exposes duplicate `Organization` import fatal.

---

# Review Notes

Audit branch contains report only. No production code was changed.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
