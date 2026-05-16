````md
# Entraide — AI Development Guide

This file is the main operational entrypoint for AI agents working on the project.

---

# Project Overview

BouclePro.com is evolving into a French multi-tenant organizational collaboration platform built with Laravel.

The platform enables members to:

- publish services
- exchange points
- communicate through messaging
- collaborate inside loops
- participate in organizational workflows
- interact with AI-assisted systems

The platform is entirely in French.

---

# Core Principles

This project prioritizes:

- tenant isolation
- business integrity
- maintainability
- predictable behavior
- explicit logic
- architectural clarity
- safe incremental refactors
- AI alignment

Critical systems must remain stable:

- transactions
- point ledger
- tenant scopes
- policies
- messaging
- organization isolation

---

# Concepts Clarifiés (T075.1)

| Concept | Rôle |
|---------|------|
| Organization | Tenant. Frontière de sécurité, billing, gouvernance. |
| Partner | Entrée co-branding / distribution. Pas un tenant. Peut pointer vers une Organization. |
| Loop | Groupe collaboratif interne à une Organization. Pas un tenant. |
| Community | Legacy technique temporaire. Community_id sera progressivement remplacé par organization_id. |
| Root domain | N'est pas tenantless pour les routes métier. Résout l'Organization par défaut. |
| Default Organization | Organization résolue pour `/{feature}` sur le root domain. |
| Partner slug route | `/{partnerSlug}/{feature}` résout l'Organization partenaire. |
| Platform global route | Route sans Organization (`/`, `/login`, `/admin/*`, `/partners`). |
| Organization-scoped public route | Route publique mais Organization-scopée. Public ≠ global. |

---

# Organization Migration Context

The platform is actively migrating from:

```text
Community → Organization
````

Official architectural rules:

```text
Organization = Tenant
Loop ≠ Tenant
```

Important:

* Organization is the official business and tenant concept
* Community is now legacy terminology
* Loops are collaborative contexts
* Loops are NOT security boundaries
* Loops are NOT tenant scopes

Current target architecture:

```text
Platform
└── Organization
    └── Loops
        └── Members
            └── Interactions
```

---

# Legacy Compatibility Rules

Current Laravel implementation may still use:

```text
Community
community_id
current_community
ResolveCommunity
BelongsToTenantScope
```

This is temporary compatibility debt.

Agents MUST:

* prefer Organization naming in new code
* avoid introducing new Community terminology
* preserve backward compatibility
* avoid uncontrolled rewrites
* avoid giant search/replace migrations

Migration strategy must remain:

* incremental
* layer-by-layer
* Playwright-safe
* SQLite-compatible
* MCP-assisted

---

# Runtime Tenant Resolution

Current canonical runtime resolution pattern:

```php
$organization = app()->bound('current_organization')
    ? app('current_organization')
    : (app()->bound('current_community')
        ? app('current_community')
        : null);
```

Rules:

* prefer `current_organization`
* preserve `current_community` compatibility
* never break compatibility abruptly

---

# AI Operating System

This repository uses a multi-agent orchestration system.

Agents MUST read:

```text
AGENTS.md
```

before starting any task.

Tasks are stored inside:

```text
TODO/
```

Each task is:

* persistent
* auditable
* handoff-compatible
* multi-agent compatible

The TASK file is the operational source of truth.

---

# Deterministic Orchestration

This project uses script-driven tooling for consistent multi-agent task orchestration.

Why:

- AI agents are interchangeable — workflow must not depend on agent memory
- Scripts enforce deterministic preconditions at each lifecycle stage
- Shared tooling eliminates agent-specific workflow paths
- CI-aware finalization prevents state drift between TASK and git

The core pipeline:

```text
check-task.sh → finalize-task.sh → merge-task.sh
```

Each stage gates on verifiable state:

- CHECK requires status DONE + lock UNLOCKED + task branch
- FINALIZE requires passing CHECK before commit/push/CI check
- MERGE requires clean git + passing CHECK + explicit confirmation

This is not optional ceremony. It is the backbone of multi-agent coordination.

Without it, agents cannot reliably determine task state, ownership, or completeness.

---

# Mandatory Task Discipline

MANDATORY TASK UPDATE BEFORE COMMIT

Before ANY commit or push, agents MUST:

* update task file
* update progress log
* update tests section
* update review notes
* update handoff if ownership changed

Commits without task updates are considered invalid workflow.

---

# Documentation Map

## Operational AI Documentation

Read:

```text
ai/context/
ai/workflows/
ai/tooling/
```

Important files:

```text
ai/context/architecture.md
ai/context/multi-tenant.md
ai/workflows/review-process.md
ai/workflows/tenant-safety.md
```

---

## Product & Architecture Documentation

Main human-oriented documentation:

```text
docs/
```

Critical references:

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

* docs/ is the human source of truth
* ai/context/ contains operational AI summaries
* avoid duplicating documentation unnecessarily
* avoid terminology drift

---

# Vocabulary Stabilization Rules

Canonical terminology:

* Organization
* Loop
* Member
* Platform
* Interaction
* Workflow
* Tenant

Rules:

* avoid terminology drift
* avoid mixing Organization and Loop concepts
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

# Development Environment

Read:

```text
ai/environment.md
```

This file contains:

* WSL configuration
* browser tooling
* Playwright access
* database environments
* installed tooling
* standard commands

---

# Tooling Hierarchy

Agents should follow this priority:

1. ai/tooling scripts
2. Laravel Boost MCP tools
3. installed modern tooling
4. raw shell commands

Avoid bypassing project tooling unless necessary.

---

# Installed System Tooling

Available tooling includes:

* rg
* batcat
* fzf
* lazygit
* gh
* tmux

Prefer modern tooling when appropriate.

Examples:

```text
rg instead of grep
batcat instead of cat
```

---

# Tooling Philosophy

Scripts exist to eliminate ambiguity across agent boundaries.

Rules:

- prefer ai/scripts/*.sh over ad-hoc commands
- prefer explicit validation over assumed state
- prefer gated workflows over free-form processes
- never bypass check-task.sh before finalize
- never bypass finalize-task.sh before merge

The scripts are intentionally:

- explicit — no hidden behavior
- safe — confirmation prompts before destructive actions
- verifiable — exit codes, status checks
- minimal — no unnecessary abstraction

This philosophy extends to all project tooling: raw commands are the last resort.

---

# Git Rules

* never push directly to main
* main = production protected branch
* develop = integration branch
* use one branch per task
* never switch branches with dirty git status
* prefer small focused commits
* push frequently for early CI visibility
* keep commits focused
* inspect git diff before commit
* avoid unrelated modifications

Branch example:

```text
TASK-058-organization-migration
```

---

# Frontend Rules

Never assume frontend behavior without validation.

Always inspect:

* browser behavior
* DOM state
* console errors
* responsive layout
* Livewire requests
* Alpine synchronization

Playwright and browser tooling are strongly encouraged.

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
* tenant isolation
* organization isolation
* messaging
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

---

# Backend Safety Rules

Before modifying:

* transactions
* point systems
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

---

# Multi-Tenant Safety Rules

Tenant isolation is CRITICAL.

Never:

* bypass tenant scopes casually
* expose cross-organization data
* assume Loop isolation is sufficient
* leak organization data through Livewire
* bypass policies without validation

Current compatibility layer may still use:

```text
community_id
ResolveCommunity
current_community
```

This remains temporarily acceptable.

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

# UI & Product Principles

BouclePro must feel:

* calm
* conversational
* lightweight
* trustworthy
* human
* intentional

Avoid:

* dashboard overload
* noisy UI
* futuristic gimmicks
* unnecessary complexity
* fake AI-chatbot experiences

AI should:

* reduce friction
* simplify workflows
* accelerate action

NOT:

* simulate fake conversations
* overwhelm users
* create artificial complexity

Detailed UI rules are documented inside:

```text
01-UI_RULES.md
02-PRODUCT_PRINCIPLES.md
03-COMPONENT_LIBRARY.md
```

---

# Testing Rules

Critical domains require validation:

* tenant isolation
* transactions
* points ledger
* policies
* APIs
* Livewire flows
* organization isolation

Prefer:

* feature tests
* integration tests
* business-flow tests
* Playwright validation

---

# Runtime Stability

All runtimes are stable:

* SQLite
* PostgreSQL
* PostgreSQL CI

Future changes must preserve dual runtime compatibility and CI parity.

---

# Important Rules

Never:

* bypass tenant isolation
* remove protections without validation
* perform uncontrolled rewrites
* modify unrelated systems
* ignore task logging
* hide architectural side effects
* mix Organization and Loop concepts

---

# Final Philosophy

Think before coding.

Inspect before modifying.

Validate before merging.

Prefer:

* conceptual clarity
* incremental migration
* compatibility layers
* architecture stability
* maintainable solutions

Avoid:

* giant rewrites
* architecture drift
* terminology drift
* premature over-engineering

Priority order:

```text
conceptual clarity
→ documentation alignment
→ AI alignment
→ architecture stabilization
→ code migration
```

The goal is:

```text
A stable, understandable, organization-native V1
```

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application.

## Foundational Context

Main stack:

* php - 8.4
* laravel/framework - v13
* livewire/livewire - v4
* laravel/boost - v2
* laravel/mcp - v0
* laravel/sanctum - v4
* phpunit/phpunit - v12
* alpinejs - v3
* tailwindcss - v3

---

# Laravel Boost Rules

## Documentation Search

Always use:

```text
search-docs
```

before making framework-level changes.

---

## Preferred MCP Tools

Prefer Laravel Boost tools over manual exploration:

* search-docs
* database-schema
* database-query
* browser-logs
* get-absolute-url

---

# Laravel Rules

Prefer Laravel-native patterns.

Use:

```bash
php artisan make:
```

commands whenever possible.

Avoid unnecessary custom infrastructure.

---

# Livewire Rules

Prefer:

* lightweight Livewire components
* server-driven interactions
* progressive enhancement

Avoid:

* giant components
* duplicated state handling
* excessive frontend complexity

---

# PHPUnit Rules

Prefer:

* feature tests
* policy tests
* integration tests
* business-flow validation

Run minimal targeted tests before finalizing.

---

# Pint Rules

If PHP files are modified:

```bash
vendor/bin/pint --dirty --format agent
```

must be executed before finalizing.

</laravel-boost-guidelines>
```
