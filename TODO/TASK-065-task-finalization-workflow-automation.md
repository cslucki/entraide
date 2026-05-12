---
task_id: TASK-065
title: task-finalization-workflow-automation

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-065-task-finalization-workflow-automation

priority: MEDIUM

created_at: 2026-05-12 17:44:28 Europe/Paris
updated_at: 2026-05-12 18:00:00 Europe/Paris

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

Design a SAFE and MINIMAL workflow automation layer for task finalization.

Three new scripts:
- `ai/scripts/check-task.sh`    — verify task readiness
- `ai/scripts/finalize-task.sh` — commit, push, CI check
- `ai/scripts/merge-task.sh`    — safe merge into develop

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [ ] validate UI

---

# Progress Log


## 2026-05-12 17:44:28 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-065-task-finalization-workflow-automation

Status:
IN_PROGRESS

## 2026-05-12 17:48:00 Europe/Paris

Audited existing workflow:

Existing scripts audited:
- ai/scripts/safe-commit.sh — interactive commit + push, main protection
- ai/scripts/handoff-task.sh — YAML-based TASK handoff with Python inline
- ai/scripts/validate-task-update.sh — git hook, blocks commit without TASK update
- ai/scripts/install-hooks.sh — installs pre-commit hook
- ai/scripts/create-task.sh — generates TASK file + git branch

Existing workflows audited:
- ai/workflows/task-lifecycle.md — lifecycle states
- ai/tooling/git-workflow.md — git philosophy
- AGENTS.md — full operational rules

CI:
- .github/workflows/ci-postgresql.yml — runs on push to main/develop and PRs

Identified gap: no scripts exist for the finalization phase (check DONE status,
verify unlock, commit task updates, push, CI check, merge to develop).

## 2026-05-12 17:50:00 Europe/Paris

Implementation complete:

1. ai/scripts/check-task.sh
   - detects current branch
   - detects TASK file (by arg or branch auto-detect)
   - verifies status == DONE
   - verifies lock == UNLOCKED
   - detects uncommitted changes
   - exit code 0 = all pass, 1 = any fail

2. ai/scripts/finalize-task.sh
   - runs check-task.sh first
   - interactive commit of TASK updates
   - interactive push to origin
   - optional gh CLI GitHub Actions status check
   - prints merge instructions

3. ai/scripts/merge-task.sh
   - safety: blocks main/develop as source
   - requires clean git status
   - fetches latest develop
   - checkout + pull develop
   - merge --no-ff (explicit, conflicts halt the script)
   - push develop
   - verify clean status post-merge

All scripts follow existing patterns:
- set -e
- consistent echo formatting
- BASE_DIR hardcoded (same convention as handoff-task.sh)
- explicit user confirmation steps
- no hidden destructive operations

Tested:
- bash syntax validated for all 3 scripts
- check-task.sh correctly fails on IN_PROGRESS/LOCKED task
- check-task.sh auto-detects task file from branch name

## 2026-05-12 18:00:00 Europe/Paris

Hardening pass applied:

1. Dirty-check: replaced `git diff --name-only` with `git status --porcelain`
   - Now detects untracked files (??), staged, unstaged, modified
   - Applied consistently in check-task.sh, finalize-task.sh, merge-task.sh

2. YAML parsing: replaced fragile grep chains with inline Python parser
   - Extracts YAML frontmatter between `---` delimiters
   - Reliably reads `status` and `lock.status` even with nesting
   - Same lightweight Python pattern as existing handoff-task.sh
   - No dependencies added

3. Removed `git add .`: replaced with explicit `git add TODO/ && git add ai/scripts/`
   - No risk of committing dumps, screenshots, .env files

4. Push confirmation: added explicit `read -p` before `git push origin develop`
   - merge-task.sh now requires confirmation before pushing to shared integration branch

5. CI visibility: improved gh CLI output with failure/cancelled warning
   - Lightweight Python formatting with icon and explicit ⚠ WARNING banner
   - Does NOT block — just improves operator visibility

# Handoffs

# Tests

- [x] bash syntax validation
- [x] check-task.sh execution test
- [x] git status --porcelain detection confirmed
- [x] Python YAML parser confirmed working (status + lock extraction)
- [ ] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [ ] tenant validation

---

# Test Results

bash -n ai/scripts/check-task.sh           — PASS
bash -n ai/scripts/finalize-task.sh        — PASS
bash -n ai/scripts/merge-task.sh           — PASS
bash ai/scripts/check-task.sh TASK-065     — PASS (Status: DONE, Lock: UNLOCKED)
git status --porcelain                     — Confirmed: detects untracked files
Python YAML parser                         — Confirmed: extracts status=DONE, lock.status=UNLOCKED

---

# Review Notes

Hardening changes:

1. Dirty detection:
   - Before: `git diff --name-only` (misses untracked files)
   - After: `git status --porcelain` (catches ALL changes: staged, unstaged, untracked)

2. YAML parsing:
   - Before: `grep "^status:" | sed ... | tr ...` (fragile, dupes, piping)
   - After: inline Python frontmatter parser (reliable, handles nesting, same pattern as handoff-task.sh)

3. git add:
   - Before: `git add .` (commits everything: .env.bak, dumps, screenshots)
   - After: `git add TODO/ && git add ai/scripts/` (explicit, safe)

4. Push develop:
   - Before: auto-push after successful merge
   - After: explicit confirmation prompt before `git push origin develop`

5. CI visibility:
   - Before: basic listing only
   - After: warning banner if latest workflow conclusion is failure or cancelled

All changes are safety-forward, minimal diff, no new architecture.