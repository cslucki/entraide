---
task_id: TASK-208
title: Add SMT conversation protocol skill

status: IN_PROGRESS

owner: ORCHESTRATOR

contributors: []

branch: TASK-208-add-smt-conversation-protocol-skill

priority: MEDIUM

created_at: 2026-06-03 22:22:06 Europe/Paris
updated_at: 2026-06-03 22:22:06 Europe/Paris

labels: []

lock:
  status: LOCKED
  agent: ORCHESTRATOR
  since: 2026-06-03 22:22:06 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Create a reusable skill in `.agents/skills/` to formalize the SMT + conversations protocol for ORCHESTRATOR / CODEUR / VERIFICATOR coordination through tmux and `ai-local`.

---

# Planned Actions

- [x] inspect architecture (.agents/skills/ tracking)
- [x] create TASK with official script
- [x] create skill folder structure
- [x] write SKILL.md with complete SMT protocol
- [ ] update TASK file
- [ ] commit changes
- [ ] validate with Cyril

---

# Progress Log

## 2026-06-03 22:22:06 Europe/Paris

Task created.

Owner:
ORCHESTRATOR

Branch:
TASK-208-add-smt-conversation-protocol-skill

Status:
IN_PROGRESS

## 2026-06-03 22:30:00 Europe/Paris

Preflight completed :

- `.agents/skills/` is tracked by Git
- Created TASK-208 with official script
- Current branch: TASK-208-add-smt-conversation-protocol-skill

Skill created :

- Folder: `.agents/skills/tmux-smt-conversation-protocol/`
- File: `.agents/skills/tmux-smt-conversation-protocol/SKILL.md`

Content covers :

- SMT = Short Messages via Tmux definition
- ORCHESTRATOR / CODEUR / VERIFICATOR roles
- Source hierarchy (AGENTS.md → TASK → code → conversation → roles)
- SMT format with allowed statuses
- Conversation entry format
- Rules for each agent
- Examples of SMT messages
- Anti-patterns
- DB safety rules

No code Laravel modified.
No DB touched.
No SUPERVISOR term reintroduced (uses CODEUR instead).

Next: Update TASK file, commit changes.

# Handoffs

# Tests

- [ ] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [ ] tenant validation

---

# Test Results

Pending.

---

# Review Notes

Pending.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`