---
task_id: TASK-116
title: t080-2-safe-sync-preflight-dry-run-guard

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-116-t080-2-safe-sync-preflight-dry-run-guard

priority: MEDIUM

created_at: 2026-05-22 22:51:33 Europe/Paris
updated_at: 2026-05-22 22:54:15 Europe/Paris

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

Implement a non-destructive safe sync preflight / dry-run guard for future production-to-local DB and storage sync operations.

---

# Planned Actions

- [x] inspect T080.1 safety protocol
- [x] inspect existing sync scripts
- [x] implement non-destructive preflight guard
- [x] verify OK/WARN/FAIL reporting
- [x] verify destructive action rejection
- [x] update task and protocol docs

---
# Progress Log


## 2026-05-22 22:51:33 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-116-t080-2-safe-sync-preflight-dry-run-guard

Status:
IN_PROGRESS

## 2026-05-22 22:54:15 Europe/Paris

T080.2 implemented by CODEX-OPS.

Files inspected:
- docs/architecture/02-PROD_LOCAL_SYNC_STRATEGY.md
- ai/scripts/pg-dump.sh
- ai/scripts/media-pull.sh
- ai/scripts/switch-db.sh
- .gitignore
- TODO/TASK-116-t080-2-safe-sync-preflight-dry-run-guard.md

Files created/modified:
- Created: ai/scripts/safe-sync-preflight.sh
- Modified: docs/architecture/02-PROD_LOCAL_SYNC_STRATEGY.md
- Modified: TODO/TASK-116-t080-2-safe-sync-preflight-dry-run-guard.md

Implemented guard:
- Checks current branch.
- Reports git status.
- Checks local environment without printing secrets.
- Checks required variable names in .env.pgsql without printing values.
- Checks existing scripts.
- Checks dumps/storage/public storage paths.
- Checks local PostgreSQL availability without printing credentials.
- Checks production credential file presence and permissions without reading values.
- Detects dangerous/sensitive pg-dump.sh paths.
- Rejects action arguments by default.
- Outputs OK/WARN/FAIL summary.

Example dry-run:
- Command: ./ai/scripts/safe-sync-preflight.sh --dry-run
- Result: WARN
- Summary observed: OK 28, WARN 11, FAIL 0.
- Warnings were expected in the development state: uncommitted task/script changes, .env presence not read, local PostgreSQL unreachable or invalid credentials hidden, dangerous commands detected but not executed.

Anti-destruction test:
- Command: ./ai/scripts/safe-sync-preflight.sh prod-mirror
- Result: FAIL
- Reason: action rejected by preflight; no dump/import/sync/migration/cache/Laravel Cloud command executed.

Scope confirmations:
- No real dump executed.
- No local import executed.
- No storage sync executed.
- No migration executed.
- No Laravel Cloud command executed.
- No PROD operation.
- No ALPHA operation.
- No runtime Laravel code modified.
- No secrets displayed.

Status:
DONE

# Handoffs

# Tests

- [x] safe preflight dry-run
- [x] destructive argument rejection
- [x] bash syntax check
- [x] git diff whitespace check
- [x] task workflow check pending

---

# Test Results

Executed:
- ./ai/scripts/safe-sync-preflight.sh --dry-run
- ./ai/scripts/safe-sync-preflight.sh prod-mirror
- bash -n ai/scripts/safe-sync-preflight.sh
- git diff --check

Results:
- Dry-run produced RESULT: WARN with no FAIL.
- Destructive action probe produced RESULT: FAIL by rejecting the action argument.
- Bash syntax check passed.
- git diff --check passed.

Runtime tests were not run because this is non-runtime operator tooling.

---

# Review Notes

The guard is intentionally conservative. It does not create directories, switch databases, read `.env` into output, execute PostgreSQL dump/import operations, pull media, run migrations, clear cache, or call Laravel Cloud.

Next step:
- GLOBAL/Cyril review before merge.
