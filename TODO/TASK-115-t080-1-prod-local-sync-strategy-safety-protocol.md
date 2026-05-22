---
task_id: TASK-115
title: t080-1-prod-local-sync-strategy-safety-protocol

status: MERGED

owner: OPENCODE

contributors: []

branch: TASK-115-t080-1-prod-local-sync-strategy-safety-protocol

priority: MEDIUM

created_at: 2026-05-22 22:20:00 Europe/Paris
updated_at: 2026-05-22 22:31:07 Europe/Paris

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

Produce a short durable prod/local sync strategy and safety protocol before any real dump, import, storage sync, migration, or Laravel Cloud operation.

---

# Planned Actions

- [x] start from clean up-to-date develop
- [x] create local CAO mission file under ignored @DOCS/cao/runs
- [x] mini-audit CAO Codex availability without global changes
- [x] inspect existing sync-related docs and scripts read-only
- [x] produce tracked safety protocol
- [x] document validation and handoff

---
# Progress Log


## 2026-05-22 22:20:00 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-115-t080-1-prod-local-sync-strategy-safety-protocol

Status:
IN_PROGRESS

## 2026-05-22 22:45:00 Europe/Paris

T080.1 completed by CODEX-OPS.

Created local ignored mission file:
- @DOCS/cao/runs/T080.1-prod-local-sync-strategy.md

CAO mini-audit:
- `cao --help` works.
- `cao install --help` lists provider choices including `codex`, `claude_code`, and `claude_glm`.
- `cao launch --help` supports `--provider TEXT`.
- `cao agents list` and `cao providers list` do not exist.
- `cao session list` reported no active sessions.
- `command -v codex` found `/home/cyril/.nvm/versions/node/v22.22.2/bin/codex`.
- `codex --version` reported `codex-cli 0.133.0`.
- Conclusion: CAO exposes a `codex` provider option and local Codex CLI exists, but Codex via CAO was not proven with a launched worker. No global CAO install/config change was made.
- Worker launched: no. CODEX-OPS completed the audit directly to avoid unproven provider setup.

Read-only inputs inspected:
- ai/scripts/pg-dump.sh
- ai/scripts/media-pull.sh
- ai/scripts/switch-db.sh
- ai/environment.md
- .env.example
- @DOCS/tech/Laravel_Cloud.md
- @DOCS/tech/Dump_dossier+fichier.md

Tracked protocol produced:
- docs/architecture/02-PROD_LOCAL_SYNC_STRATEGY.md
- docs/README.md updated to index the architecture document.

Protocol contents:
- DB prod -> local strategy split into dry-run, production dump, local import, and stabilization phases.
- Storage/images/uploads strategy based on targeted media pulls rather than blind full mirror.
- Secrets guardrails: do not read/print `.env`, credentials, `DATABASE_URL`, passwords, keys, or tokens.
- Destructive command guardrails for `pg_restore --clean`, `import`, `reset`, `prod-mirror`, migrations, and cache clear.
- Dry-run protocol for branch, git status, tools, local PostgreSQL, gitignored dump path, and credential file permission checks.
- Rollback protocol requiring pre-import local dump identification before destructive import.
- Command matrix with read-only, explicit authorization, production-read, local-destructive, and forbidden command classes.
- Laravel Cloud prerequisites and automation boundaries.

Local CAO mission file was replaced with the ROADMAP-provided French mission text:
- @DOCS/cao/runs/T080.1-prod-local-sync-strategy.md

Scope confirmations:
- No real dump executed.
- No local import executed.
- No storage sync executed.
- No migration executed.
- No runtime code modified.
- No main / PROD operation.
- No ALPHA operation.

Recommendation:
- Next task should be T080.2 for guarded preflight/dry-run tooling only, or BUG_BACKLOG_TRIAGE if ROADMAP returns priority to product/runtime defects.

Status:
DONE

## 2026-05-22 22:31:07 Europe/Paris

T080.1 merged into develop via official workflow.

Merge details:
- Official script used: ./ai/scripts/merge-task.sh TASK-115-t080-1-prod-local-sync-strategy-safety-protocol
- Source branch: TASK-115-t080-1-prod-local-sync-strategy-safety-protocol
- Target branch: develop
- Develop push completed by merge-task.sh.
- No remote branch deletion performed.

Scope confirmations:
- No runtime code modified.
- main / PROD not touched.
- ALPHA not touched.
- No dump, import, storage sync, migration, or CAO tooling phase executed during merge.

Status:
MERGED

# Handoffs

# Tests

- [x] git status validation
- [x] read-only script/doc inspection
- [x] CAO command availability mini-audit
- [x] protocol reviewed against T080.0 risks
- [x] runtime tests intentionally not run

---

# Test Results

Read-only documentation/protocol task.

No PHPUnit, Playwright, migration, dump, import, sync, or runtime validation executed.

Validation commands used:
- git status --short --branch
- git pull --ff-only
- cao --help
- cao install --help
- cao launch --help
- cao info
- cao session list
- command -v codex
- codex --version
- sed -n on approved sync/docs assets

Pending official gate:
- ai/scripts/check-task.sh

---

# Review Notes

The main safety decision is to split future prod/local sync into separately authorized phases. `prod-mirror` should not be treated as the default path because it combines production dump, destructive local import, migrations, and cache clear.

CAO Codex status:
- Available as a provider option in CAO help.
- Not proven as a working launched provider during this task.
- No CAO worker launched.

Files modified:
- TODO/TASK-115-t080-1-prod-local-sync-strategy-safety-protocol.md
- docs/architecture/02-PROD_LOCAL_SYNC_STRATEGY.md
- docs/README.md

Local ignored file created:
- @DOCS/cao/runs/T080.1-prod-local-sync-strategy.md

Handoff:
- T080.2 can implement guarded preflight/dry-run tooling only.
- BUG_BACKLOG_TRIAGE remains the alternate next step if runtime/product stabilization is prioritized.
