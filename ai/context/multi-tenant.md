> **AGENT CONTEXT ONLY**
> Short tenant-safety checklist for agents. Not canonical documentation. If this file conflicts with `docs/`, `docs/` wins.

# Multi-Tenant Agent Context

## Role Of This File

- Use this file before touching tenant resolution, routing, scopes, policies, Livewire data loading, AI context, or public business surfaces.
- Treat it as an operational checklist, not product doctrine or migration canon.
- Keep durable concepts and migration plans in `docs/`.
- Read `ai/context/current-state.md` first for current ROADMAP constraints.

## Canonical Sources To Read

- `docs/README.md`
- `docs/05-DOMAIN_ARCHITECTURE.md`
- `docs/06-GLOSSARY.md`
- `docs/architecture/01-ROOT_DOMAIN_TENANT_RESOLUTION.md`
- `docs/migration/01-COMMUNITY_MIGRATION_STRATEGY.md`
- `docs/migration/02-ORGANIZATION_MIGRATION_EXECUTION_PLAN.md`
- `ai/context/architecture.md`
- `ai/context/routing-strategy.md`

## Non-Negotiable Rules

- Organization = Tenant.
- Loop ≠ Tenant.
- Partner ≠ Tenant.
- Public route ≠ global route.
- Member belongs inside an Organization context.
- Interaction belongs inside Organization-scoped product behavior.
- Business features must resolve an Organization or fail closed.
- No business data may be loaded outside a resolved Organization unless the route is explicitly Platform-global.
- `Community`, `community_id`, `current_community`, and `ResolveCommunity` are temporary legacy technical compatibility only.
- Do not introduce new Community vocabulary in product, docs, prompts, UI, or new code.

## Runtime Compatibility

Current compatibility code may still bind both names to the same tenant instance.

```php
$organization = app()->bound('current_organization')
    ? app('current_organization')
    : (app()->bound('current_community')
        ? app('current_community')
        : null);
```

Rules:

- Prefer `current_organization` in new work.
- Preserve `current_community` fallback until the migration plan removes it.
- Keep explicit `community_id` foreign-key mappings where current models still require them.
- Do not rename columns, routes, middleware, or model relationships through broad search/replace.

## Tenant-Safety Checklist

Before changing tenant-sensitive behavior, verify:

- Which Organization is resolved for the request.
- Whether the route is Platform-global or Organization-scoped.
- Whether public data is still Organization-scoped.
- Whether policies enforce the same Organization boundary as queries.
- Whether Eloquent scopes, manual queries, and route-model binding can leak cross-Organization data.
- Whether Livewire components hydrate with the correct Organization context.
- Whether AI prompts, memories, embeddings, settings, and automation stay Organization-scoped.
- Whether tests or browser checks cover isolation, redirects, and unauthorized access.

## Before Tenant Or Routing Changes

- Inspect routes, middleware, controllers, policies, models, scopes, views, and tests before editing.
- Confirm the change follows the migration order documented in `docs/migration/02-ORGANIZATION_MIGRATION_EXECUTION_PLAN.md`.
- Keep compatibility additive unless a task explicitly authorizes removal.
- Keep existing Playwright routes stable unless the task is specifically about Playwright route migration.
- Document any contradiction between `ai/context/*` and `docs/` in the TASK file before changing runtime behavior.

## Platform-Global Exceptions

Some routes may intentionally run without an Organization, such as authentication, legal pages, sitemap, partner landing/request routes, or platform admin.

Rules:

- Global behavior must be explicit.
- Admin bypasses must be permission-checked.
- Public business surfaces remain Organization-scoped unless canonical docs say otherwise.
- `/boucles`, `/partenaires`, and `/partenaires/demande` follow the routing rules summarized in `ai/context/routing-strategy.md`.

## Out Of Scope For This File

- Detailed route matrices.
- Full migration execution plans.
- Database migration recipes.
- Product definitions for Organization, Loop, Member, Interaction, Flux, Signaux, or Journal.
- Historical Community-era documentation.
- Implementation-specific code samples beyond the current runtime compatibility fallback.

## Related Agent Context

- Current ROADMAP state and scope limits: `ai/context/current-state.md`.
- Architecture overview and high-risk areas: `ai/context/architecture.md`.
- Public French routing and root-domain route rules: `ai/context/routing-strategy.md`.
