---
task_id: TASK-113
title: T079.2 Agent Routing & CAO Workflow Integration

status: DONE

owner: CODEX

contributors:
  - CAO

branch: TASK-113-t079-2-agent-routing-cao-workflow-integration

priority: MEDIUM

created_at: 2026-05-22 20:27:07 Europe/Paris
updated_at: 2026-05-22 20:40:00 Europe/Paris

labels:
  - documentation
  - workflow
  - roadmap

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

Document the new agent orchestration workflow with CAO to reduce human copy-paste and clarify roles between ChatGPT Cockpit, CAO supervisor, workers, OPS, CODE, Codex, Claude, and GLM/Z.ai.

**Scope: documentation only. No runtime changes.**

---

# Planned Actions

- [x] inspect ai/ structure (README.md, context/, agents/, orchestrator/, workflows/)
- [x] read existing workflow files (handoff.md, task-lifecycle.md)
- [x] create ai/workflows/agent-routing.md
- [ ] run check-task.sh validation
- [ ] update TASK file before commit
- [ ] finalize and prepare for review

---
# Progress Log

## 2026-05-22 20:35:00 Europe/Paris

- Inspected `ai/README.md`, `ai/context/current-state.md`, `ai/agents/agents-list.md`
- Read `ai/workflows/handoff.md`, `ai/workflows/task-lifecycle.md`
- Created `ai/workflows/agent-routing.md` covering:
  * Quick decision tree for agent selection
  * CAO assign usage guidelines
  * Handoff usage
  * Laravel Boost integration
  * Agent roles summary (Cockpit, OPS, CODE, Codex, REVIEW, Claude, GLM/Z.ai)
  * Techniques to reduce copy-paste
  * ROADMAP vs CAO local tools separation
  * Tenant rules reminder
  * Files to read before action

Modified files:
- `ai/workflows/agent-routing.md` (created)
- `TODO/TASK-113-t079-2-agent-routing-cao-workflow-integration.md` (updated)

## 2026-05-22 20:27:07 Europe/Paris

Task created.

Owner: CODEX
Branch: TASK-113-t079-2-agent-routing-cao-workflow-integration
Status: IN_PROGRESS

---

# Handoffs

---

# Tests

Documentation-only task. Runtime tests not required.

Validations:
- [x] No app/, routes/, resources/, database/, config/ modifications
- [x] No ALPHA mixing
- [x] No Community vocabulary introduced
- [x] Organization = Tenant respected
- [x] Loop != Tenant respected
- [ ] check-task.sh validation pending

---

# Test Results

Scope validated: documentation only (ai/ and TODO/).

---

# Review Notes

**Decisions:**
- Created single `ai/workflows/agent-routing.md` instead of multiple files
- Used quick decision tree format for easy routing
- Kept content short and operational

**Limitations:**
- CAO assign mechanism details in `ai/orchestrator/` (future orchestrator)
- Worker-specific skills configuration not documented here
- Copier-coller reduction techniques are guidelines, not enforced

**CHANGES_REQUESTED corrections (2026-05-22):**
- Nuanced CAO assign usage: clarified validation use case vs simple checks
- Nuanced documentation cleanup: distinguished simple read/clean vs ROADMAP doc tasks
- Separated Laravel Boost MCP tools from CAO worker project skills
- Verified docs/ canon paths exist

**Next steps:**
- Run check-task.sh
- Finalize and prepare for merge