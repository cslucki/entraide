# Agents List

## Primary Agents

| Agent | Role | Priority |
|---|---|---|
| GLM | Main orchestrator, primary coder, task coordination | PRIMARY |
| JULES | Frontend specialist, Blade, Alpine.js, UI implementation | PRIMARY |
| CLAUDE | Architecture, backend, reviews, advanced reasoning | SECONDARY |

---

## Secondary Agents

| Agent | Role |
|---|---|
| GEMINI | Analysis, reviews, research, alternative reasoning |
| CODEX | Advanced coding assistance |
| OPENCODE | Future usage |
| DEEPSEEK | Alternative reasoning and coding support |

---

# Multi-Agent Rules

- Multiple agents may contribute to the same task
- One task = one source of truth
- One task = one git branch
- Handoffs are mandatory between agents
- Agents must update task logs continuously
- All actions must be timestamped using Europe/Paris timezone

---

# Task Ownership

Each task may contain:

- one primary owner
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
4. log intended actions

Agents must not work simultaneously on the same files without explicit coordination.

---

# Handoff Rules

When an agent stops working:

- update task status
- write current state
- list modified files
- list pending actions
- unlock task

This allows another agent to continue safely.

---

# Branch Strategy

One git branch per task.

Example:

TASK-051-navbar-livewire-fix

Do not create one branch per agent.

The task is the source of truth, not the agent.

---

# Review Rules

Before review:

- tests must run
- browser validation must be completed
- console errors must be checked
- tenant safety must be verified
- task log must be updated

---

# Forbidden Actions

Agents must never:

- push directly to main
- bypass tenant isolation
- remove tests without justification
- modify unrelated systems
- ignore task logging
- overwrite another agent's work