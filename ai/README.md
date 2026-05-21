# Agent Operating Guide

`ai/` contains the operating layer for agents working on BouclePro / Cyberworkers.

## Source Separation

- `docs/` = canonical project documentation: what the project is.
- `ai/` = agent operations: scripts, tooling, workflows, prompts and context summaries.
- `@DOCS/` = private human documentation, outside the tracked repository and not a source for agents.

Agents must not carry durable product or architecture truth only in `ai/`.

## Required Reading Order

1. `AGENTS.md` if present
2. `docs/README.md`
3. `ai/README.md`
4. relevant canonical docs in `docs/`
5. relevant `ai/context/*` or `ai/workflows/*`
6. the current TASK file

## Canonical Rule

If `ai/context/*`, prompts, workflows or local notes conflict with `docs/`, `docs/` wins.

`ai/context/*` files are operational summaries for agents. They can point to canonical sources, but they must not become a parallel product doctrine.

## Official Scripts

- Start with `ai/scripts/check-task.sh` when validating a task branch state.
- Use `ai/scripts/finalize-task.sh` before task closure when the TASK is ready.
- Use `ai/scripts/merge-task.sh` only on explicit instruction.

Do not merge without explicit instruction.

## Tooling

Use `ai/tooling/` before raw shell exploration when possible.

Preferred inspection tools remain:
- Laravel Boost MCP tools when relevant
- `rg`
- `batcat`
- project scripts in `ai/scripts/`

## Directory Roles

| Directory | Role |
|---|---|
| `ai/agents/` | Agent list and role notes. |
| `ai/config/` | Agent/tool configuration. |
| `ai/context/` | Short operational summaries for agents; not canonical documentation. |
| `ai/decisions/` | Agent-operation ADRs or decisions waiting to be mirrored into `docs/` when durable. |
| `ai/orchestrator/` | Orchestration notes and handoff support. |
| `ai/playwright/` | Browser QA support and generated visual artifacts. |
| `ai/prompts/` | Reusable agent prompt templates. |
| `ai/scripts/` | Official task lifecycle scripts. |
| `ai/tasks/` | Task templates and operational task support. |
| `ai/tooling/` | Preferred inspection and execution tooling. |
| `ai/workflows/` | Task, review, handoff and tenant-safety process. |

## Scope Discipline

- Keep changes inside the requested scope.
- Do not modify runtime files during documentation-only tasks.
- Do not move durable project decisions into `ai/context/*`.
- Update the TASK file with scope, validations, and handoff state before finalization.
