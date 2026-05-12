````md
# Entraide — Multi-Agent Operating System

This file defines the global multi-agent operating workflow used by all AI agents working on the project.

Technical architecture, Laravel conventions, migration strategy and AI operational context are documented inside:

- `CLAUDE.md`
- `ai/environment.md`
- `ai/context/*`
- `ai/workflows/*`
- `ai/tooling/*`
- `docs/*`

---

# Core Philosophy

The system is:

- task-centric
- multi-agent
- persistent
- auditable
- handoff-friendly
- architecture-aware
- migration-safe

The TASK file is the operational source of truth.

NOT the agent.

---

# AI Operating Model

This repository is designed for coordinated AI orchestration.

Agents may include:

- GLM
- Claude
- OpenCode
- Codex
- Gemini
- Jules
- DeepSeek

Agents are interchangeable contributors.

The workflow continuity is preserved through:

- TASK files
- progress logs
- structured handoffs
- timestamps
- architectural rules
- migration documentation

---

# Mandatory Task Lifecycle

TASK files are mandatory operational memory.

Agents MUST:

- read the TASK file before implementation
- continuously update the TASK file
- append progress after each major step
- document discoveries
- document architecture decisions
- document executed tests
- document blockers/errors
- document modified files
- update TASK before commit

A TASK file is NOT optional documentation.

No hidden work.

---

# Mandatory Task Discipline

MANDATORY TASK UPDATE BEFORE COMMIT

Before ANY commit or push, agents MUST:

- update task status
- update progress log
- update tests section
- update review notes
- update blockers if needed
- update handoff if ownership changed

Commits without task updates are invalid workflow.

The pre-commit hook validates this automatically:

```bash
ai/scripts/validate-task-update.sh
```

It blocks any commit without a staged TASK file change.

The hook is installed via:

```bash
ai/scripts/install-hooks.sh
```

All agents MUST install hooks in their environment.

Emergency bypass: `SKIP_TASK_CHECK=1` (use only when hook infrastructure is unavailable).

---

# Source Of Truth

Each task is represented by one markdown file inside:

```text
TODO/
````

Example:

```text
TODO/TASK-058-organization-migration.md
```

This file is the source of truth for:

* ownership
* status
* progress
* architecture decisions
* blockers
* reviews
* tests
* handoffs
* timestamps

---

# One Task = One Branch

Each task uses ONE branch.

Example:

```text
TASK-058-organization-migration
```

Rules:

* do NOT create one branch per agent
* do NOT mix unrelated work
* one task = one primary objective
* the TASK owns the branch

Avoid:

* mixed migrations
* opportunistic refactors
* unrelated fixes
* architecture rewrites during feature work

---

# Task Lifecycle

Official lifecycle:

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

* one owner
* multiple contributors

Example:

```yaml
owner: GLM

contributors:
  - CLAUDE
  - JULES
```

---

# Lock System

Before modifying a task:

1. lock the task
2. update timestamps
3. document intended actions
4. inspect current progress

Example:

```yaml
lock:
  status: LOCKED
  agent: GLM
  since: 2026-05-11 20:41:11 Europe/Paris
```

Agents must not modify the same files simultaneously without coordination.

---

# Handoff System

When stopping work:

* update progress
* document current state
* list modified files
* list pending actions
* list known risks
* unlock task

The handoff system enables safe continuation by another AI system.

---

# Mandatory Workflow Sequence

Complete lifecycle from task creation to merge:

1. CREATE — `ai/scripts/create-task.sh "<title>" <owner>`
   generates TASK file, creates branch, locks to owner, status IN_PROGRESS

2. IMPLEMENT — agent works
   read TASK first, update progress continuously, document decisions

3. HANDOFF (if needed) — `ai/scripts/handoff-task.sh <TASK_ID> <new_owner>`
   transfers ownership, updates lock, documents current state

4. CHECK — `ai/scripts/check-task.sh [TASK_ID]`
   verify status==DONE, lock==UNLOCKED, not on main/develop, report uncommitted changes

5. FINALIZE — `ai/scripts/finalize-task.sh [TASK_ID]`
   gate: runs check-task.sh first, commit TASK updates, push, optional CI check

6. MERGE — `ai/scripts/merge-task.sh [TASK_ID]`
   gate: requires clean status, --no-ff into develop, confirmation before push

7. CLOSE — update TASK status to MERGED, delete branch

Each stage gate prevents proceeding without satisfying preconditions.

The TASK file and git state must remain synchronized at every stage.

---

# Mandatory Logging

Agents MUST continuously update:

* progress logs
* tests
* blockers
* handoffs
* review notes
* modified files

All entries must contain timestamps.

Example:

```text
2026-05-11 20:41:11 Europe/Paris
```

---

# Task State & Git State

The TASK file and git state are dual sources of truth that must remain synchronized.

```text
TODO        — branch exists, no implementation changes
IN_PROGRESS — branch with active work (staged/unstaged/untracked)
BLOCKED     — branch, work paused, blockers documented
TESTING     — branch, implementation complete, validation ongoing
IN_REVIEW   — branch, commits pushed, awaiting review
DONE        — branch, all commits pushed, ready to merge
MERGED      — merged into develop, branch may be deleted
ARCHIVED    — task closed, branch deleted
```

Rules:

- TASK status must always reflect actual work state
- DONE requires all changes committed and pushed
- DONE also requires green CI (GitHub Actions passing, PostgreSQL CI stable, runtime parity preserved)
- MERGED requires successful merge into develop via merge-task.sh
- never mark DONE without check-task.sh passing
- never merge without finalize-task.sh completing
- never set MERGED without merge-task.sh succeeding

Scripts enforce this synchronization automatically.

---

# Machine-Readable Format

Each TASK begins with YAML metadata.

Example:

```yaml
---
task_id: TASK-058
status: IN_PROGRESS
owner: GLM

contributors:
  - CLAUDE

branch: TASK-058-organization-migration
---
```

This structure enables:

* orchestration
* automation
* AI interoperability
* future tooling

---

# Organization Migration Rules

The platform is actively migrating from:

```text
Community → Organization
```

Official architecture rules:

```text
Organization = Tenant
Loop ≠ Tenant
```

This distinction is CRITICAL.

---

# Official Conceptual Rules

## Organization

Organization represents:

* tenant boundary
* security boundary
* billing boundary
* governance boundary

---

## Loop

Loops are:

* collaborative contexts
* relational groups
* operational spaces

Loops are NOT:

* tenants
* security scopes
* DB isolation layers

---

# Migration Constraints

Migration MUST remain:

* incremental
* compatibility-first
* Playwright-safe
* SQLite-compatible
* MCP-assisted
* architecture-safe

Migration MUST NOT:

* use giant search/replace
* perform uncontrolled rewrites
* bypass tenant isolation
* mix unrelated refactors
* destabilize Livewire hydration

---

# Legacy Compatibility Rules

Current Laravel implementation may still use:

```text
Community
community_id
ResolveCommunity
current_community
BelongsToTenantScope
```

This remains temporarily acceptable.

Agents MUST:

* prefer Organization terminology in new code
* avoid introducing new Community terminology
* preserve compatibility layers
* preserve migration stability
* avoid abrupt breaking changes

---

# Runtime Tenant Resolution

Current canonical runtime resolution:

```php
$organization = app()->bound('current_organization')
    ? app('current_organization')
    : (app()->bound('current_community')
        ? app('current_community')
        : null);
```

Rules:

* prefer `current_organization`
* preserve `current_community`
* never break compatibility abruptly

---

# Preferred Migration Order

Mandatory migration order:

1. database
2. models
3. middleware
4. routes
5. controllers
6. policies
7. Livewire
8. views
9. PHPUnit
10. Playwright

Do NOT skip layers.

---

# Documentation Hierarchy

## Operational AI Documentation

Read first:

```text
ai/context/
ai/workflows/
ai/tooling/
```

Critical files:

```text
ai/context/architecture.md
ai/context/multi-tenant.md
ai/workflows/review-process.md
ai/workflows/tenant-safety.md
```

---

## Product & Architecture Documentation

Main references:

```text
docs/
```

Critical documents:

```text
01-UI_RULES.md
02-PRODUCT_PRINCIPLES.md
04-ENGINEERING_RULES.md
06-DOMAIN_ARCHITECTURE_V2.md
07-GLOSSARY.md
08-COMMUNITY_MIGRATION_STRATEGY.md
09-ORGANIZATION_MIGRATION_EXECUTION_PLAN.md
```

Rules:

* docs/ = human source of truth
* ai/context/ = operational AI summaries
* avoid duplicated concepts
* avoid terminology drift

---

# Vocabulary Stabilization Rules

Canonical terminology:

* Organization
* Loop
* Member
* Platform
* Module
* Interaction
* Workflow
* Tenant

Rules:

* preserve conceptual clarity
* avoid terminology drift
* preserve vocabulary consistency across:

  * code
  * prompts
  * UI
  * documentation
  * AI systems

Forbidden conceptual confusion:

```text
Loop = Tenant
```

This is FALSE.

Official rule:

```text
Organization = Tenant
```

---

# Tooling Hierarchy

Agents should follow this order:

1. `ai/tooling`
2. Laravel Boost MCP tools
3. installed modern tooling
4. raw shell commands

Avoid bypassing project tooling unless necessary.

---

# Official Tooling Workflow

The project provides official scripts for every stage of the task lifecycle.

## Task Creation

`ai/scripts/create-task.sh "<title>" <owner>`

Generates TASK file, sets status IN_PROGRESS, locks to owner, creates git branch.

## Handoff

`ai/scripts/handoff-task.sh <TASK_ID> <new_owner>`

Transfers ownership, updates contributors, re-locks to new owner, documents transition.

## Pre-Commit Validation

`ai/scripts/validate-task-update.sh`

Git hook: blocks commits without a staged TASK file change.

Install via `ai/scripts/install-hooks.sh`.

## Task Check

`ai/scripts/check-task.sh [TASK_ID]`

Gate for finalization. Verifies:

- on a task branch (not main/develop)
- TASK file exists (auto-detected from branch or argument)
- status == DONE
- lock == UNLOCKED
- reports uncommitted changes

Exit codes: 0 = pass, 1 = fail.

## Task Finalization

`ai/scripts/finalize-task.sh [TASK_ID]`

Completes the task branch lifecycle:

1. runs check-task.sh — must pass
2. optional commit (TASK + script changes only, explicit paths)
3. optional push to origin
4. optional CI status inspection via gh CLI
5. prints next steps

Expectations:

- run only after implementation is fully validated
- TASK must be DONE and UNLOCKED
- commit message format: `task: <summary>` or `finalize(task): <summary>`
- finalize-task.sh CI check is advisory (prepares finalization, not a CI gate)
- does NOT merge — use merge-task.sh

CI model:

- finalize-task.sh prepares finalization
- GitHub Actions provides external distributed validation
- green CI required before merge (merge discipline)

## Task Merge

`ai/scripts/merge-task.sh [TASK_ID]`

Safely merges into develop:

- blocks on main/develop source branch
- requires clean git status
- fetches and pulls latest develop
- --no-ff merge (explicit merge commit)
- conflicts halt the script
- confirmation before push
- verifies post-merge status

Discipline:

- never merge without passing check-task.sh
- never merge with uncommitted changes
- green CI required before merge
- never use --ff-only or squash — requires explicit merge commits
- update TASK to MERGED after merge succeeds

---

# Preferred MCP Tools

Preferred Laravel Boost tools:

* `search-docs`
* `database-schema`
* `database-query`
* `browser-logs`
* `get-absolute-url`

Agents SHOULD prefer MCP tooling over blind shell exploration.

---

# Installed System Tooling

Available tooling includes:

* rg
* batcat
* fzf
* lazygit
* gh
* tmux

Preferred examples:

```text
rg instead of grep
batcat instead of cat
```

---

# Git Workflow Rules

Never:

* push directly to main
* create giant mixed commits
* modify unrelated systems
* commit without updating TASK
* switch branches with dirty git status

Protected branches:

* main = production protected branch (never push directly)
* develop = integration branch (merge via merge-task.sh only)

Dirty branch switching:

Never switch branches with dirty git status.

Always commit, stash intentionally, or clean up before checkout.

Commit discipline:

* prefer small focused commits
* push frequently
* make CI visible early
* avoid local-only hidden state

Always:

* inspect git diff before commit
* keep commits focused
* preserve architectural clarity
* validate migration impact

Preferred commit examples:

```text
refactor(organization): migrate middleware runtime resolution
fix(playwright): stabilize tenant isolation selectors
feat(ai): add organization-aware prompt builder
```

---

# Multi-Agent Coordination Rules

This repository is actively modified by multiple AI systems.

Therefore:

* avoid touching unrelated files
* avoid architectural drift
* document important decisions
* preserve compatibility layers
* respect ownership boundaries

Example:

If working on:

```text
messaging
```

Do NOT refactor:

```text
transactions
tenant scopes
admin systems
```

unless explicitly required.

---

# Frontend Validation Rules

Never assume frontend correctness without validation.

Always inspect:

* browser behavior
* DOM state
* console errors
* responsive layouts
* Livewire hydration
* Alpine synchronization

Playwright validation is strongly encouraged.

---

# Playwright QA Rules

The project includes an agentic Playwright QA system.

Documentation:

```text
ai/playwright/README.md
```

Quick start:

```bash
npx playwright test
npx playwright test --ui
npx playwright show-report ai/playwright/reports/html
```

Critical validation domains:

* authentication
* organization isolation
* tenant isolation
* messaging
* transactions
* admin flows
* responsive behavior
* console errors
* Livewire stability

Artifacts:

```text
ai/playwright/screenshots/
ai/playwright/test-results/
ai/playwright/reports/html/
```

Every visual feature SHOULD provide:

* desktop screenshots
* mobile screenshots
* dark mode screenshots

Real screenshots only.

No mockups.

---

# Backend Safety Rules

Before modifying:

* transactions
* points ledger
* tenant scopes
* policies
* middleware
* organization resolution
* APIs

Always:

1. inspect architecture
2. inspect related models
3. inspect policies
4. inspect routes
5. inspect tests
6. validate side effects

Critical domains require extra caution:

* transactions
* messaging
* reviews
* admin permissions
* organization isolation

---

# Multi-Tenant Safety Rules

Tenant isolation is CRITICAL.

Never:

* bypass tenant scopes casually
* expose cross-organization data
* assume Loop isolation is sufficient
* leak organization data through Livewire
* bypass policies without validation

Current compatibility layer may still expose:

```text
community_id
ResolveCommunity
current_community
BelongsToTenantScope
```

This remains temporarily acceptable.

---

# Livewire Rules

Prefer:

* lightweight components
* server-driven interactions
* progressive enhancement
* predictable hydration

Avoid:

* giant components
* duplicated state handling
* business logic inside Blade
* excessive frontend complexity

---

# Alpine.js Rules

Use Alpine only for:

* dropdowns
* toggles
* lightweight UI interactions
* local reactive behavior

Avoid:

* complex application state
* business logic
* nested reactive systems

---

# AI Architecture Rules

AI systems must remain:

* configurable
* provider-agnostic
* prompt-driven
* organization-scoped

Avoid:

* hardcoded AI behavior
* duplicated prompts
* AI logic scattered everywhere

Preferred architecture:

* providers
* factories
* prompt builders
* centralized settings

---

# UI & Product Philosophy

BouclePro must feel:

* calm
* conversational
* lightweight
* trustworthy
* intentional
* human

Avoid:

* dashboard overload
* noisy interfaces
* futuristic gimmicks
* fake chatbot experiences
* unnecessary complexity

AI should:

* reduce friction
* simplify workflows
* accelerate action

NOT:

* simulate fake conversations
* overwhelm users
* create artificial complexity

---

# UI Engineering Rules

Prefer:

* clear hierarchy
* whitespace
* readable typography
* lightweight interactions
* responsive-first layouts

Avoid:

* dense dashboards
* visual overload
* excessive animations
* giant forms
* inconsistent spacing

Minimum validations:

* desktop
* mobile
* dark mode
* Playwright screenshots

---

# Testing Rules

Critical systems require validation:

* tenant isolation
* organization isolation
* transactions
* point ledger
* policies
* APIs
* messaging
* Livewire flows

Prefer:

* feature tests
* integration tests
* business-flow tests
* Playwright validation

---

# Runtime Stabilization Rules

Current runtime state:

* SQLite runtime = stable
* PostgreSQL runtime = stable
* PostgreSQL CI = stable

All future work MUST preserve:

* dual runtime compatibility
* CI parity between SQLite and PostgreSQL
* Playwright stability across runtime environments

---

# Forbidden Actions

Agents must NEVER:

* push directly to main
* bypass tenant isolation
* remove protections without validation
* overwrite another agent's work
* ignore task logging
* perform uncontrolled rewrites
* modify unrelated systems
* introduce terminology drift
* mix Loop and Organization concepts

---

# Review Philosophy

Prefer:

* small safe changes
* explicit logic
* maintainable code
* compatibility layers
* predictable behavior
* incremental migration

Avoid:

* architecture drift
* hidden side effects
* uncontrolled rewrites
* premature abstractions
* giant migration PRs

---

# Strategic Principle

Priority order:

```text
conceptual clarity
→ documentation alignment
→ AI alignment
→ architecture stabilization
→ code migration
```

NOT the reverse.

---

# Final Philosophy

Think before coding.

Inspect before modifying.

Validate before merging.

Prefer:

* conceptual stability
* maintainable Laravel patterns
* organization-native architecture
* incremental migration
* compatibility-first refactors

The goal is:

```text
A stable, understandable, organization-native V1
```
