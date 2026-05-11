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

# Organization Migration Context

The platform is currently migrating from:

Community → Organization

Important:

- Organization is now the official business and tenant concept.
- Community is considered legacy terminology.
- Existing Laravel code may still use:
  - Community
  - community_id
  - current_community
  - community middleware

This is temporary technical debt.

Agents must:
- prefer Organization naming in new code,
- avoid introducing new Community terminology,
- preserve backward compatibility during migration,
- avoid large uncontrolled rewrites.

Current target architecture:

Platform
└── Organization
    └── Loops
        └── Members
            └── Interactions

Important:

- Loops are collaborative contexts.
- Loops are NOT tenant boundaries.
- Organization is the tenant boundary.

Migration strategy:
- incremental,
- layer-by-layer,
- Playwright-safe,
- SQLite-compatible,
- MCP-assisted.

Avoid:
- massive search/replace,
- uncontrolled migrations,
- architecture drift.


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

Agents MUST prefer project tooling from ai/tooling and ai/scripts over ad-hoc shell commands whenever equivalent tooling exists.

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

Read: "ai/tooling/"

This directory contains:

* official project tooling
* operational scripts
* standardized workflows

Examples:

* ai/tooling/git-status.sh
* ai/tooling/git-diff-files.sh
* ai/tooling/playwright-run.sh
* ai/tooling/playwright-report.sh
* ai/tooling/task-check.sh

---

# Installed System Tooling

The environment also provides system-level tools:

* rg
* batcat
* fzf
* lazygit
* gh
* tmux

Agents should prefer these tools over older Unix equivalents when appropriate.

Examples:

* use `rg` instead of `grep`
* use `batcat` instead of `cat`

---

# Tooling Hierarchy

Agents should follow this priority:

1. ai/tooling scripts
2. installed modern tooling
3. raw shell commands

Avoid bypassing project tooling unless necessary.

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

# Playwright QA System

The project includes an agentic Playwright QA system for browser testing.

**Documentation:** `ai/playwright/README.md`

**Quick Start:**
```bash
npx playwright test                    # Run all tests
npx playwright test --ui             # UI mode for debugging
npx playwright show-report ai/playwright/reports/html
```

**Helpers Available:**
```javascript
import { loginAsMember, loginAsAdmin, login, logout } from '../../ai/playwright/helpers/auth.js';
import { captureScreenshot } from '../../ai/playwright/helpers/screenshot.js';
import { setupConsoleLogging } from '../../ai/playwright/helpers/console.js';
```

**Strict Account Separation:**
- `loginAsAdmin()` - Uses TEST_ADMIN_* for /admin/* routes ONLY
- `loginAsMember()` - Uses TEST_MEMBER1_* for global platform tests (OUTSIDE CPME)
- CPME accounts reserved for future tenant-isolation testing

**Test Users for local testing:**
See .env and `ai/playwright/README.md` for details about test users.

**Artifacts Location:**
- Screenshots: `ai/playwright/screenshots/`
- Traces: `ai/playwright/test-results/`
- Videos: `ai/playwright/test-results/`
- Reports: `ai/playwright/reports/html/`

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

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- laravel/breeze (BREEZE) - v2
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/sanctum (SANCTUM) - v4
- livewire/livewire (LIVEWIRE) - v4
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- phpunit/phpunit (PHPUNIT) - v12
- alpinejs (ALPINEJS) - v3
- tailwindcss (TAILWINDCSS) - v3

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.
- To check environment variables, read the `.env` file directly.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== livewire/core rules ===

# Livewire

- Livewire allow to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

</laravel-boost-guidelines>
