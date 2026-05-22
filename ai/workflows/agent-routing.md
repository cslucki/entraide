> **AGENT WORKFLOW CONTEXT**
>
> This file documents when to use which agent, tool, or workflow.
> It is operational guidance, not project canon. Product and architecture truth lives in `docs/`.

# Agent Routing & CAO Workflow

## Quick Decision Tree

| Situation | Use |
|-----------|-----|
| New ROADMAP task | Create TASK file, then assign via CAO |
| Existing task continuation | Continue on current agent or handoff if quota/blocked |
| Code implementation with Laravel | Use laravel_developer worker via CAO assign |
| Documentation cleanup | Simple read/clean: CODEX or Claude direct; ROADMAP doc task: TASK file + branch |
| Code review | Use REVIEW worker or Claude |
| OPS/finalize/merge | Use OPS / OpenCode |
| Read-only inspection | Use any agent (no CAO needed) |

---

## CAO Assign Usage

**Use CAO assign when:**

- Starting a new ROADMAP task
- Worker execution needed on an existing task
- Laravel-specific implementation required
- Need a worker with Laravel Boost access + skills maison

**Use CAO assign for validation when:**

- Testing the worker pipeline, skills, Laravel Boost, or agent behavior
- Read-only validation with explicit testing goal

**Avoid CAO assign for:**

- Simple read-only checks without testing objective
- One-off shell commands
- Exploratory research without structured goal

**CAO local tools:** Available without CAO assign for quick operations.

---

## Handoff Usage

**Use handoff when:**

- Agent reaches quota limits
- Another agent type is needed
- Review delegation
- Debugging delegation
- Task ownership transfer

**Handoff format:** See `ai/workflows/handoff.md`.

---

## Laravel Boost Usage

**Use Laravel Boost when:**

- Any Laravel PHP code work (controllers, models, migrations)
- Database schema inspection
- Application info needed
- Error log diagnosis

**Laravel Boost includes:**

- PHP 8.4, Laravel 13.7.0 environment
- MCP tools: database-schema, database-query, browser-logs, search-docs

**CAO workers may also have project skills:**

- laravel-conventions
- entraide-domain
- git-workflow

---

## Agent Roles Summary

| Agent | Role | When to Use |
|-------|------|-------------|
| ChatGPT Cockpit | Orchestration with Cyril | Roadmap steering, task framing, arbitration |
| OPS / OpenCode | Branch & task operations | TASK files, finalize, merge on instruction |
| CODEX | Documentation & refactors | Short doc tasks, AI context updates |
| Codex GPT-5.5 | Sensitive implementation | Complex code changes, Laravel refactors |
| REVIEW | Audit & validation | Reviews, security checks, contradiction checks |
| Claude | Complex reasoning | Architecture-heavy work, alternate implementation |
| GLM / Z.ai | Backup support | Small tasks, targeted assistance |

---

## Reducing Copy-Paste

**Techniques:**

- Use CAO assign → worker reads context directly
- Use structured TASK file as source of truth
- Use agent handoff for continuity
- Use MCP tools (Laravel Boost) for direct data access
- Reference canonical `docs/` instead of duplicating

**Avoid:**

- Copying large code blocks into prompts
- Re-stating TASK file content in conversations
- Manual context passing when automation exists

---

## ROADMAP vs CAO Local Tools

**ROADMAP tasks:**

- Require TASK file creation via `create-task.sh`
- Require explicit branch
- Require `check-task.sh`, `finalize-task.sh`, `merge-task.sh`
- Scope: stable organization-native V1

**CAO local tools:**

- Available without TASK creation
- Read-only by default
- For inspections, quick checks, T7 validation
- Scope: auxiliary, not ROADMAP work

**Do not mix:**
- CAO local tools are not for ROADMAP implementation
- ROADMAP tasks require proper lifecycle scripts

---

## Tenant Rules Reminder

- Organization = Tenant
- Loop != Tenant
- Community/current_community/community_id = legacy temporaire
- No new Community vocabulary in product, docs, prompts, UI

---

## Files to Read Before Action

| Context | Files |
|---------|-------|
| General | `AGENTS.md`, `ai/README.md`, `ai/agents/agents-list.md` |
| Task lifecycle | `ai/workflows/task-lifecycle.md`, `ai/workflows/handoff.md` |
| Tenant safety | `ai/workflows/tenant-safety.md` |
| Project canon | `docs/README.md`, `docs/05-DOMAIN_ARCHITECTURE.md`, `docs/06-GLOSSARY.md` |