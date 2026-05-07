# Entraide — AI Development Guide

This file is the main entrypoint for AI agents working on the project.

---

# Project Overview

BouclePro.com is a French multi-tenant peer-to-peer service exchange platform built with Laravel.

Users:
- publish services
- exchange points
- communicate through messaging
- interact inside isolated communities

The platform is entirely in French.

---

# Core Principles

This project prioritizes:

- tenant isolation
- business integrity
- maintainability
- predictable behavior
- explicit logic
- safe incremental refactors

Critical systems must remain stable:
- transactions
- point ledger
- tenant scopes
- policies
- messaging

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

# AI Operating System

This repository uses a multi-agent orchestration system.

Agents must read:

- `AGENTS.md`

before starting any task.

Tasks are stored inside:

```text
TODO/
```

Each task is:
- persistent
- auditable
- multi-agent compatible
- handoff-compatible

The task file is the source of truth.

---

# Development Environment

Read:

```text
ai/environment.md
```

This file contains:
- WSL environment
- network configuration
- Playwright/browser access
- production environment
- database differences
- installed tooling
- standard commands

---

# Architecture Documentation

Read:

```text
ai/context/
```

Important files:
- architecture.md
- multi-tenant.md
- business-rules.md
- testing-strategy.md
- development-philosophy.md

---

# Workflows

Read:

```text
ai/workflows/
```

Important workflows:
- debugging.md
- livewire-debug.md
- review-process.md
- tenant-safety.md

---

# Tooling

Read:

```text
ai/tooling/
```

Installed tools include:
- batcat
- rg
- fzf
- lazygit
- tmux

Agents should prefer these tools when available.

Examples:
- use `rg` instead of `grep`
- use `batcat` instead of `cat`

---

# Prompts

Specialized prompts are stored inside:

```text
ai/prompts/
```

Examples:
- architect.md
- performance.md
- reviewer.md
- security.md
- ux.md

---

# Task Workflow

Before starting work:

1. read AGENTS.md
2. inspect existing tasks
3. lock the task
4. update task metadata
5. create/update branch
6. log all actions

---

# Git Rules

- never push directly to main
- use one branch per task
- keep commits focused
- inspect git diff before commit
- avoid unrelated modifications

Branch example:

```text
TASK-051-navbar-livewire-fix
```

---

# Frontend Rules

Never assume frontend behavior without validation.

Always inspect:
- browser behavior
- DOM state
- console errors
- responsive layout
- Livewire requests
- Alpine synchronization

Browser tooling and Playwright are strongly encouraged.

---

# Backend Rules

Before modifying:
- transactions
- point systems
- tenant scopes
- policies
- APIs

Always:
1. inspect architecture
2. inspect related models
3. inspect tests
4. validate side effects

---

# Testing Rules

Critical domains require validation:
- tenant isolation
- transactions
- points ledger
- policies
- APIs
- Livewire flows

Prefer:
- feature tests
- integration tests
- business-flow tests

---

# Important Rules

Never:
- bypass tenant isolation
- remove protections without validation
- perform uncontrolled rewrites
- modify unrelated systems
- ignore task logging
- hide architectural side effects

---

# Final Philosophy

Think before coding.

Inspect before modifying.

Validate before merging.

Prefer small safe changes over large rewrites.
