# Engineering Workflow Rules

---

# Git Workflow

## Branch Strategy

Each feature MUST have its own branch.

Examples:

* `feature/ai-homepage`
* `feature/referral-system`
* `feature/notification-center`
* `feature/admin-ai`

Avoid:

* mixed-purpose branches
* unrelated commits
* direct commits to main

---

## Merge Strategy

Never merge directly into `main`.

Workflow:

1. Feature branch
2. Merge into `develop`
3. QA & validation
4. UI harmonization if needed
5. Merge `develop -> main`

---

## Commit Discipline

Commits must be:

* small
* focused
* understandable

Avoid:

* giant commits
* mixed frontend/backend changes without explanation
* “fix stuff” commit messages

Preferred commit style:

* `feat: add conversational homepage input`
* `fix: correct mobile notification spacing`
* `refactor: extract reusable conversation input component`

---

# Multi-Agent Coordination

This project is developed with multiple AI agents.

Therefore:

* avoid touching unrelated files
* avoid refactoring outside task scope
* document architectural decisions
* respect ownership boundaries

---

## Scope Protection

If working on:

* notifications

DO NOT modify:

* homepage
* admin AI
* referral system

Unless explicitly requested.

---

# Testing Rules

Every feature requires:

## Backend

* Feature tests
* Unit tests when needed

## Frontend

* Playwright validation
* mobile validation
* dark mode validation

---

# Screenshot Requirements

Every visual feature MUST provide:

* desktop screenshots
* mobile screenshots
* dark mode screenshots

Real screenshots only.
No mockups.

---

# Playwright Rules

Playwright is mandatory for:

* responsive validation
* navigation validation
* UI regression checks
* AI interaction validation

Capture:

* successful user flows
* empty states
* menu interactions
* conversational interactions

---

# Tailwind Rules

Prefer:

* reusable component extraction
* consistent spacing
* utility clarity

Avoid:

* giant unreadable class strings
* duplicated utility groups
* inconsistent spacing systems

---

# Livewire Rules

Prefer:

* small focused components
* server-driven interactions
* progressive enhancement

Avoid:

* giant Livewire components
* business logic inside Blade
* duplicated state handling

---

# Alpine.js Rules

Alpine should remain lightweight.

Use only for:

* dropdowns
* local interactions
* UI toggles
* small reactive behavior

Avoid:

* complex application state
* business logic
* excessive nested x-data

---

# AI Architecture Rules

AI logic must remain:

* configurable
* provider-agnostic
* prompt-driven

Avoid:

* hardcoded AI behavior
* prompt duplication
* AI logic spread everywhere

Preferred structure:

* providers
* factories
* prompt builders
* centralized settings

---

# Prompt Management

Prompts should be:

* editable
* versioned
* centralized

Avoid:

* hidden prompts in controllers
* inline giant prompt strings
* duplicated instructions

---

# Admin UX Rules

Admin tools should:

* expose control clearly
* remain readable
* avoid overwhelming complexity

Admin interfaces must still feel premium.

---

# Error Handling

Errors must:

* help users recover
* remain human-readable
* avoid technical jargon

Avoid:

* raw exception dumps
* cryptic validation messages

---

# Performance Rules

Prioritize:

* perceived speed
* reduced JS
* fast rendering
* minimal dependencies

Avoid:

* frontend bloat
* unnecessary polling
* excessive reactivity

---

# Security Rules

Never expose:

* API keys
* internal prompts
* provider secrets
* admin-only configuration

AI calls must remain server-side.

---

# Production Safety

Before final validation, always provide:

* composer changes
* npm changes
* migrations
* env changes
* queue requirements
* cache requirements

Deployment must remain predictable.

---

# Documentation Rules

Every major feature must document:

* architecture
* decisions
* limitations
* reusable components
* future extension points

---

# Product Philosophy

The product must feel:

* coherent
* intentional
* calm
* scalable

Not:

* improvised
* overloaded
* “AI-generated”

The user experience must always feel curated by humans.
