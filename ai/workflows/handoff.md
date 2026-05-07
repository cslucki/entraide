# Agent Handoff Workflow

## Goal

Allow safe continuation of work between multiple AI agents.

The task file is the source of truth.

---

# When A Handoff Is Required

A handoff is required when:

- an agent reaches quota limits
- an agent stops working
- another agent takes over
- review is delegated
- debugging is delegated

---

# Mandatory Handoff Actions

Before stopping work, the agent must:

1. update task status
2. update timestamps
3. document current state
4. list modified files
5. describe remaining work
6. describe blockers if any
7. unlock the task

---

# Required Handoff Format

```markdown
# Handoff

Timestamp:
2026-05-07 18:42:11 Europe/Paris

Current Status:
IN_PROGRESS

Current Agent:
GLM

Next Recommended Agent:
JULES

Modified Files:
- resources/views/livewire/navbar.blade.php
- app/Livewire/Navbar.php

Completed Work:
- fixed duplicate refresh
- reduced polling frequency

Remaining Work:
- mobile responsive validation
- console inspection

Warnings:
- possible Alpine.js desynchronization
```

---

# Important Rules

Handoffs must be:
- explicit
- complete
- auditable

No hidden work.

