# Entraide — Multi-Agent Operating System

This file defines the global multi-agent workflow used by all AI agents working on the project.

Technical architecture, environment and Laravel conventions are documented inside:

- `CLAUDE.md`
- `ai/environment.md`
- `ai/context/*`
- `ai/workflows/*`
- `ai/tooling/*`

---

# Core Philosophy

The system is:

- task-centric
- multi-agent
- persistent
- auditable
- handoff-friendly

The task is the source of truth, not the agent.

---
# MANDATORY TASK LIFECYCLE

TASK files are the operational memory of the project.

Agents MUST:
- read the TASK file before any implementation
- continuously update the TASK file during execution
- append progress after each major step
- document architecture decisions
- document discoveries
- document executed tests
- document blockers/errors
- update TASK before commit

A TASK file is NOT optional documentation.
---

# Mandatory Task Discipline

MANDATORY TASK UPDATE BEFORE COMMIT

Before ANY commit or push, agents MUST:
- update the task file
- update progress log
- update tests section
- update review notes
- update handoff if ownership changed

Commits without task updates are considered invalid workflow.

---
# The project AI tooling is part of the architecture itself.

Agents should not bypass project tooling unless:
- debugging tooling itself
- no equivalent tooling exists
- emergency diagnosis is required

Do not use raw git status/diff commands when ai/tooling equivalents exist.
---

# What Is An Agent

An agent is an AI system capable of:

- reading tasks
- modifying code
- running tests
- updating task logs
- coordinating through handoffs
- respecting architectural rules

Agents may include:

- GLM
- JULES
- CLAUDE
- GEMINI
- CODEX
- OPENCODE
- DEEPSEEK

---

# Source Of Truth

Each task is represented by a single markdown file inside:

```text
TODO/
```

Example:

```text
TODO/TASK-051-navbar-livewire-fix.md
```

This file is the single source of truth for:

- status
- ownership
- progress
- handoffs
- tests
- reviews
- timestamps

---

# One Task = One Branch

Each task uses exactly one git branch.

Example:

```text
TASK-051-navbar-livewire-fix
```

Do not create one branch per agent.

The task owns the branch.

---

# Task Lifecycle

Tasks follow this lifecycle:

```text
TODO
IN_PROGRESS
BLOCKED
TESTING
IN_REVIEW
DONE
MERGED
ARCHIVED
```

---

# Task Ownership

Each task may contain:

- one owner
- multiple contributors

Example:

```yaml
owner: GLM

contributors:
  - JULES
  - CLAUDE
```

---

# Lock System

Before modifying a task:

1. lock the task
2. update task status
3. update timestamps
4. document intended actions

Example:

```yaml
lock:
  status: LOCKED
  agent: GLM
  since: 2026-05-07 16:42:11 Europe/Paris
```

Agents must not work simultaneously on the same files without coordination.

---

# Handoff System

When an agent stops working:

- update task log
- describe current state
- list modified files
- list pending actions
- unlock the task

This allows another agent to continue safely.

---

# Mandatory Logging

Agents must continuously update:

- progress logs
- tests
- handoffs
- blockers
- review notes
- modified files

All operations must include timestamps.

Example:

```text
2026-05-07 16:42:11 Europe/Paris
```

---

# Machine-Readable Format

Each task begins with YAML metadata.

Example:

```yaml
---
task_id: TASK-051
status: IN_PROGRESS
owner: GLM
contributors:
  - JULES
branch: TASK-051-navbar-livewire-fix
---
```

This format allows future orchestration and automation.

---

# Testing Rules

Before review:

- run relevant tests
- inspect browser behavior
- inspect console errors
- validate responsive behavior
- validate tenant safety
- verify architectural consistency

---

# Browser & Playwright Rules

Agents are encouraged to use browser tooling for:

- screenshots
- responsive testing
- DOM inspection
- Livewire debugging
- Alpine.js validation
- console inspection

Do not assume frontend behavior without browser validation.

---

# Forbidden Actions

Agents must never:

- push directly to main
- bypass tenant isolation
- remove protections without justification
- overwrite another agent's work
- ignore task logging
- modify unrelated systems
- bypass architectural rules

---

# Review Philosophy

Prefer:

- small safe changes
- explicit logic
- maintainability
- predictable behavior
- incremental refactors

Avoid:

- architecture drift
- hidden side effects
- uncontrolled rewrites
- premature abstractions

---

# Important Rule

The task file must always reflect the real current state of the work.

The task log is mandatory.

No hidden work.
