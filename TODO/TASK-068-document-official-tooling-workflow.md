---
task_id: TASK-068
title: document-official-tooling-workflow

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-068-document-official-tooling-workflow

priority: MEDIUM

created_at: 2026-05-12 19:10:28 Europe/Paris
updated_at: 2026-05-12 19:55:00 Europe/Paris

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

Document and stabilize the official multi-agent task lifecycle tooling workflow inside AGENTS.md and CLAUDE.md.

No runtime changes. No tooling rewrites. No git hook modifications. No migration work.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] validate no duplication/drift

---
# Progress Log


## 2026-05-12 19:10:28 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-068-document-official-tooling-workflow

Status:
IN_PROGRESS

# Handoffs

# Tests

- [x] syntax validation (bash -n on all referenced scripts)
- [x] grep-based consistency validation across AGENTS.md + CLAUDE.md
- [ ] feature tests (docs only — no runtime changes)
- [ ] browser validation (docs only)

---

# Test Results

All script references verified:
- AGENTS.md: 14 references to check-task.sh, finalize-task.sh, merge-task.sh
- CLAUDE.md: 3 references (pipeline + philosophy rules)
- No contradictory statements with docs/* reference files

---

# Review Notes

Consolidation pass completed.

Additions to AGENTS.md:
- One task = one objective (+ avoid list)
- CI discipline: finalize-task.sh advisory, GitHub Actions validation, green CI required before merge
- DONE requires green CI
- Runtime Stabilization Rules (SQLite + PostgreSQL stable)
- Git Workflow Rules: dirty branch switching forbidden, protected branches (main/develop), commit discipline
- Pre-commit hook enforcement detailed

Additions to CLAUDE.md:
- Git Rules: expanded (protected branches, dirty branch switch, small commits, push for CI)
- Runtime Stability section

No massive reorganization. No rewriting of existing sections. No duplication. No runtime changes.