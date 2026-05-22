> **AGENT CONTEXT ONLY**
> Short operational architecture reminder for agents. Not canonical documentation. If this file conflicts with `docs/`, `docs/` wins.

# Architecture Agent Context

## Role Of This File

- Use this file as a quick architecture orientation before changing behavior.
- Use `ai/context/current-state.md` first for the latest ROADMAP and workflow state.
- Use specialized agent context files for tenant and routing details.
- Do not treat this file as product, domain, migration, or routing source of truth.

## Canonical Sources To Read

- `docs/README.md`
- `docs/05-DOMAIN_ARCHITECTURE.md`
- `docs/06-GLOSSARY.md`
- `docs/architecture/01-ROOT_DOMAIN_TENANT_RESOLUTION.md`
- `docs/migration/01-COMMUNITY_MIGRATION_STRATEGY.md`
- `docs/migration/02-ORGANIZATION_MIGRATION_EXECUTION_PLAN.md`
- `ai/context/current-state.md`
- `ai/context/multi-tenant.md`
- `ai/context/routing-strategy.md`

## Agent Architecture Principles

- BouclePro is a Laravel platform for organization-native collaboration.
- Keep documentation changes short, operational, and handoff-friendly.
- Prefer canonical docs for durable concepts and `ai/context/` for agent workflow reminders.
- Preserve compatibility layers during migration; do not rewrite architecture through broad search/replace.
- Inspect models, routes, policies, middleware, and tests before changing shared behavior.
- Validate critical behavior with focused tests or browser checks when implementation changes occur.
- Keep AI behavior configurable, provider-agnostic, prompt-driven, and Organization-scoped.

## Main Domains

- Organization: tenant, governance, billing, ownership, and security boundary.
- Loop: collaborative context inside an Organization.
- Member: person or account participating inside an Organization context.
- Interaction: product behavior such as messaging, transactions, reviews, workflows, services, or AI-assisted actions.
- Admin: platform management surface; global behavior must be explicit and documented.
- API: authorized integration surface; tenant isolation still applies.

## Organization / Loop / Member / Interaction Guards

- Organization = Tenant.
- Loop != Tenant.
- Every business feature must resolve an Organization or fail closed.
- Loops, Members, Interactions, services, messages, transactions, reviews, workflows, and AI context remain Organization-scoped.
- Public does not mean global; public business surfaces may still require Organization resolution.
- `Community`, `community_id`, and `current_community` are temporary legacy technical compatibility only.
- Do not introduce new Community vocabulary in product, prompts, UI, docs, or new code.

## Where Details Live

- Current ROADMAP and immediate constraints: `ai/context/current-state.md`.
- Tenant resolution, compatibility fallback, and isolation checklist: `ai/context/multi-tenant.md`.
- Public French routing, root-domain routing, and partner route patterns: `ai/context/routing-strategy.md`.
- Durable domain vocabulary and migration strategy: `docs/`.

## High-Risk Areas

- Tenant resolution and scopes.
- Policies and authorization.
- Transactions and point ledger.
- Messaging and Livewire hydration.
- Public routing that exposes business data.
- AI context, prompts, and Organization-specific configuration.

## Out Of Scope For This File

- Detailed route matrices.
- Migration execution plans.
- Current sprint status beyond links to `current-state.md`.
- Full tenant implementation notes.
- Product specifications or canonical domain definitions.
