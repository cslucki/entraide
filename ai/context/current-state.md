> **AGENT CONTEXT ONLY**
> Short operational snapshot for agents. Not canonical documentation. If this file conflicts with `docs/`, `docs/` wins.

# Current State After T079.0

## Roadmap State

- T079.0 Documentation Index & Agent Operating Guide is merged and CI is green.
- VK preflight for T079.1 succeeded.
- Immediate priority: T079.1 Slim Agent Context & Current State.
- T079.1 will slim `ai/context/architecture.md`, `ai/context/multi-tenant.md`, and `ai/context/routing-strategy.md`; this file is the short current-state entry point.

## Non-Negotiable Agent Rules

- `docs/` describes what the project is.
- `ai/` describes how agents work.
- `@DOCS/` is private, untracked, human context.
- Do not recreate parallel canonical documentation in `ai/context/`.
- Keep agent context short, operational, and handoff-friendly.
- Read canonical sources before architecture or tenant changes.
- Do not modify PROD, ALPHA, `main`, or protected integration flow casually.

## Tenant Rules

- Organization = Tenant.
- Loop != Tenant.
- Member belongs to an Organization context.
- Interaction belongs inside Organization-scoped product behavior.
- Business features must resolve an Organization or fail closed.
- Public routes are not automatically global.
- `Community`, `community_id`, and `current_community` are temporary legacy technical compatibility only.
- Do not introduce new Community vocabulary in product, docs, prompts, UI, or new code.

## Public French Routing

- Canonical public French routes include `/boucles`, `/partenaires`, and `/partenaires/demande`.
- `/boucles` is the target French public surface for Loops, not a tenant boundary.
- `/partenaires` and `/partenaires/demande` are platform public routes.
- A public route may still be Organization-scoped when it exposes business behavior.

## Roadmap vs ALPHA

- ROADMAP work prepares the stable organization-native V1.
- ALPHA is a separate operational surface and must not be changed during ROADMAP cleanup unless explicitly requested.
- Do not mix ROADMAP documentation cleanup with ALPHA, PROD, application code, migrations, builds, or routing implementation.

## Canonical Sources To Read

- `docs/README.md`
- `docs/05-DOMAIN_ARCHITECTURE.md`
- `docs/06-GLOSSARY.md`
- `docs/architecture/01-ROOT_DOMAIN_TENANT_RESOLUTION.md`
- `docs/migration/01-COMMUNITY_MIGRATION_STRATEGY.md`
- `docs/migration/02-ORGANIZATION_MIGRATION_EXECUTION_PLAN.md`
- `ai/README.md`

## Explicitly Out Of Scope Now

- Application code changes, migrations, build steps, long-running servers, PRs, merges, and commits.
