---
task_id: TASK-110
title: T079.0 — Documentation Index & Agent Operating Guide

status: IN_PROGRESS

owner: OPENCODE

contributors: []

branch: TASK-110-t079-0-documentation-index-agent-operating-guide

priority: MEDIUM

created_at: 2026-05-21 13:33:24 Europe/Paris
updated_at: 2026-05-21 13:33:50 Europe/Paris

labels: []

lock:
  status: LOCKED
  agent: OPENCODE
  since: 2026-05-21 13:33:24 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Open T079.0 as a clean documentation task and branch for the upcoming Documentation Index & Agent Operating Guide patch.

The implementation patch will be applied later by OPENAI / Codex on this branch.

This OPENCODE pass is limited to task and branch setup.

---

# Planned Actions

- [x] start from `develop`
- [x] run `git pull --ff-only origin develop`
- [x] verify clean git status before task creation
- [x] create task and branch with `ai/scripts/create-task.sh`
- [x] run `ai/scripts/check-task.sh` and document result
- [x] prepare TASK file only
- [ ] OPENAI / Codex applies documentation patch
- [ ] validate documentation-only diff scope
- [ ] update task status and lock according to final review workflow

Out of scope for this OPENCODE setup pass:

- no changes to `docs/`
- no changes to `ai/` outside this TASK file
- no runtime changes
- no merge

---
# Progress Log


## 2026-05-21 13:33:24 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-110-t079-0-documentation-index-agent-operating-guide

Status:
IN_PROGRESS

## 2026-05-21 13:33:50 Europe/Paris

OPENCODE setup pass for T079.0.

Actions completed:

- confirmed starting branch was `develop`
- ran `git pull --ff-only origin develop`; develop already up to date
- verified clean git status before task creation
- created task and branch with `ai/scripts/create-task.sh "T079.0 — Documentation Index & Agent Operating Guide" OPENCODE`
- created branch `TASK-110-t079-0-documentation-index-agent-operating-guide`
- created TASK file `TODO/TASK-110-t079-0-documentation-index-agent-operating-guide.md`
- ran `ai/scripts/check-task.sh`

`check-task.sh` result:

- CHECK FAILED, expected for a newly opened task because status is `IN_PROGRESS`, lock is `LOCKED`, and the TASK file was still uncommitted at that point

Scope guardrails:

- no `docs/` content changed
- no `ai/` content changed outside this TASK file
- no `app/`, `routes/`, `resources/`, `database/`, or `config/` changes
- no merge

Next owner/action:

- OPENAI / Codex may apply the reviewed documentation patch on this branch

# Handoffs

## 2026-05-21 13:33:50 Europe/Paris

Ready for OPENAI / Codex documentation implementation on branch `TASK-110-t079-0-documentation-index-agent-operating-guide`.

OPS setup only; no documentation patch has been applied yet.

# Tests

- [ ] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [ ] tenant validation

---

# Test Results

Setup validation only:

- `git pull --ff-only origin develop`: passed, already up to date
- pre-create `git status --short --branch`: clean on `develop`
- `ai/scripts/create-task.sh`: passed, created TASK-110 and branch
- `ai/scripts/check-task.sh`: failed as expected for newly opened `IN_PROGRESS` / `LOCKED` task with uncommitted TASK file

---

# Review Notes

T079.0 is intentionally opened as documentation workflow preparation only.

OPENAI / Codex should apply the documentation index / agent guide patch separately.
