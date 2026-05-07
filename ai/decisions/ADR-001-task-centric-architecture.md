# ADR-001 — Task-Centric Multi-Agent Architecture

## Status

Accepted

---

# Context

The project uses multiple AI agents:

- GLM
- JULES
- CLAUDE
- GEMINI
- others in the future

Originally, the system used one TODO file per agent.

Example:

- TODO_GLM.md
- TODO_JULES.md

This approach created limitations:
- fragmented state
- difficult handoffs
- duplicated tracking
- weak auditability
- poor scalability

---

# Decision

The system now uses a task-centric architecture.

Each task is represented by:

```text
TODO/TASK-XXX-description.md
```

The task file is the single source of truth.

Tasks:
- support multiple agents
- support handoffs
- support locks
- support auditability
- support future orchestration

---

# Consequences

Benefits:
- persistent shared memory
- safer multi-agent collaboration
- improved auditability
- easier future automation
- better scalability

Tradeoffs:
- stricter workflow discipline required
- mandatory task logging
- mandatory lock/handoff workflow

---

# Future Direction

This architecture is designed to support:

- orchestration systems
- dashboards
- automated reviews
- analytics
- task automation
- future AI coordination systems
