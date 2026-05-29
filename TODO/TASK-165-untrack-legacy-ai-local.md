---
task_id: TASK-165
title: Untrack legacy .ai-local and standardize ai-local
status: DONE
owner: SUPERVISOR
contributors:
  - SUPERVISOR
branch: TASK-165-untrack-legacy-ai-local
priority: HIGH
created_at: 2026-05-29 08:45:00 Europe/Paris
updated_at: 2026-05-29 08:50:00 Europe/Paris
labels:
  - governance
  - cleanup
  - git
lock:
  status: UNLOCKED
  agent: null
  since: 2026-05-29 08:50:00 Europe/Paris
handoff: true
pr:
  status: NOT_READY
  url: null
---

# TASK-165 — Untrack legacy `.ai-local` and standardize `ai-local`

## Objective

Remove the legacy `.ai-local/` directory from Git tracking while preserving `ai-local/` (the canonical ORCHESTRATOR memory, which has its own local Git and is excluded from the Laravel repo).

**No application code changes. No migrations. No DB changes.**

---

## Scope

| Action | File/Dir | Result |
|--------|----------|--------|
| Backup | `.ai-local/` → `/tmp/dot-ai-local-tracked-backup-20260529-125540` | ✅ 5 files preserved |
| Remove from Git | `.ai-local/` | `git rm -r .ai-local` ✅ |
| Exclude | `.git/info/exclude` | Both `ai-local/` and `.ai-local/` already present ✅ |
| Verify | `ai-local/` | ✅ Exists with own `.git` |
| Verify | `.git/info/exclude` | ✅ Both entries present |

**Do NOT touch:**
- `ai-local/` content ✅ preserved
- Application code ✅ no changes
- Migrations ✅ no changes
- `public/build/manifest.json` ✅ no changes
- main/PROD/Laravel Cloud ✅ no changes

---

## Files Removed from Git (5)
```
.ai-local/orchestrator/README.md
.ai-local/orchestrator/WORKING_AGREEMENT_CYRIL_AND_ORCHESTRATOR.md
.ai-local/orchestrator/archive/20260528-002-migration-community-org-run.md
.ai-local/orchestrator/working/current-focus.md
.ai-local/orchestrator/working/current-run.md
```

---

## `.git/info/exclude` (local, not committed)
```
ai-local/
.ai-local/
```
Both entries already present — no change needed.

---

## Backup
`/tmp/dot-ai-local-tracked-backup-20260529-125540/` contains full `.ai-local/` content.

---

# Planned Actions

- [x] Checkout develop and pull
- [x] Create branch + TASK file
- [x] Verify tracked files
- [x] Backup .ai-local
- [x] git rm -r .ai-local
- [x] Verify excludes
- [x] Update TASK
- [x] Commit and push

---

# Progress Log

## 2026-05-29 08:45:00 Europe/Paris
Task created. Branch TASK-165-untrack-legacy-ai-local.

## 2026-05-29 08:50:00 Europe/Paris
All steps completed. 5 untracked files, backup saved, excludes verified. Ready for commit.

---

# Tests
N/A — No application code changes.

---
