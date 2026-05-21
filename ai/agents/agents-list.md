# Agents List

> **AGENT OPERATING CONTEXT**
>
> This file describes the current practical agent roles for BouclePro / Cyberworkers. It is operational guidance, not project canon. Product and architecture truth lives in `docs/`.

## Current Operating Roles

| Agent / Interface | Current role | Typical use |
|---|---|---|
| ChatGPT Web / Cockpit | Orchestration with Cyril | Roadmap steering, task framing, arbitration, relay between agents. |
| OPS / OpenCode | Branch and task operations | TASK files, scripts, branch setup, finalize, merge on explicit instruction. |
| OPENAI / Codex GPT-5.5 | Sensitive implementation and refactor work | Documentation refactors, code changes, Laravel-safe implementation, high-quality patching. |
| REVIEW / OpenAI | Audit and validation | Reviews, contradiction checks, security review, documentation alignment. |
| Gemini CLI | Large-context audit and second reading | Broad scans, alternate review, large documentation/code context. |
| OpenCode Go | Lightweight support | Simple tasks, inspections, economical assistance. |
| Claude Code | Complex tasks or architecture second reading | Architecture-heavy work, complex reasoning, alternate implementation pass when useful. |
| GLM / Z.ai | Backup support | Small tasks, backup execution, targeted assistance. |
| Jules | Occasional GitHub-based support | Targeted GitHub tasks when useful. |

## Operating Rules

- One task = one source of truth.
- One task = one git branch.
- Do not create one branch per agent.
- The TASK file is the operational source of truth.
- Handoffs are mandatory when ownership changes.
- Agents must update task logs for implementation, validation and blockers.
- Do not merge without explicit instruction.

## Role Boundaries

- Cockpit decides direction with Cyril.
- OPS owns lifecycle mechanics when asked: branch, TASK, finalize, merge.
- Implementers change files inside the requested scope.
- REVIEW validates after commit/push or when explicitly asked.
- No agent should treat `ai/context/*` as canonical project doctrine.

## Forbidden Actions

Agents must never:

- push directly to main
- bypass tenant isolation
- remove tests without justification
- modify unrelated systems
- ignore task logging
- overwrite another agent's work
- merge without explicit instruction
