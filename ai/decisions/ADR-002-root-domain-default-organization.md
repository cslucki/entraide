# ADR-002 — Root Domain Default Organization Resolution

## Status

Accepted

---

# Context

The platform uses a multi-tenant architecture where:

```text
Organization = Tenant
```

Currently, there is no documented rule for how the root domain handles tenant resolution.

Scenarios:

- `https://test.laravel/` (local development)
- `https://bouclepro.com/` (production SaaS)

These URLs do not contain an explicit Organization scope (e.g. `/org/{slug}` or subdomain).

The absence of a rule creates ambiguity:

- Should the root domain be treated as "outside tenant"?
- Can business routes like `/loos`, `/dashboard`, `/services` be accessed without an Organization?
- What happens when no Organization is resolved?

Furthermore, the legacy system previously used `Community` as both tenant and collaborative group. The migration to `Organization` as tenant and `Loop` as collaborative group requires explicit documentation of root domain behavior.

---

# Decision

The root domain is **not tenantless**.

All access to the root domain operates within an Organization context.

## Resolution Strategy

Two canonical approaches are accepted:

1. **Internal resolution** — the root domain implicitly resolves a default Organization (e.g. public home page with implicit tenant context).
2. **Redirect** — the root domain redirects to an Organization-scoped canonical route (e.g. `/org/{slug}`).

## Guard State

If no Organization can be resolved from:
- the route,
- the host,
- the authenticated user,
- or an explicit default Organization,

the application must fail safely with a documented guard state (404, 410, or setup redirect).

## Critical Rules

- A Loop is **never** created outside an Organization.
- A Loop **never** becomes the tenant.
- The root domain must never be treated as a "no-tenant zone" for business features.
- This rule applies to all environments (dev, staging, production).
- Business routes (`/loos`, `/loos/create`, `/admin/loops`, `/dashboard`, `/services`, `/requests`, `/messages`, `/blog`, `/membres`) must resolve an Organization before loading or creating data.

---

# Consequences

## Positive

- Eliminates ambiguity about root domain tenant resolution.
- Prevents security breaches where root domain is treated as "outside tenant".
- Aligns with the `Organization = Tenant` principle.
- Provides clear documentation for future implementation.
- Enables safe design of landing pages, auth flows, and onboarding.

## Negative

- Requires explicit default Organization configuration for each environment.
- Adds complexity to the routing layer (needs Organization resolution before business routes).
- May require redirect logic or middleware for root domain access.

## Neutral

- Does not prescribe which resolution strategy (internal vs redirect) to use — that is a future implementation decision.
- Legacy `Community` / `community_id` internals remain untouched.

---

# Out of Scope

- Implementation of root domain resolution middleware.
- Changes to routes, controllers, or models.
- Database migration.
- Community → Organization migration.
- Definition of what constitutes the "default Organization".
- Authentication flows at the root domain.
- Landing page design.

---

# Impact on T75/T76

## T75

T75 may need to account for the root domain resolution rule when:

- implementing or modifying Organization resolution middleware,
- designing public-facing routes that must resolve a tenant,
- adjusting the routing strategy for Organization-scoped URLs.

The documentation in ADR-002 provides the architectural foundation that T75 must respect.

## T76

T76 may need to account for this rule when:

- dealing with admin routes on the root domain that require Organization context,
- implementing fallback/guard states for unresolved Organizations,
- ensuring admin panels correctly resolve the default Organization.

No code change is required in T75 or T76 as a direct consequence of this ADR.

The ADR exists solely to document the rule before implementation work begins.

---

# Related Documents

- `ai/context/architecture.md` — "Root Domain Resolution" section
- `docs/06-DOMAIN_ARCHITECTURE_V2.md` — section 9.5 "Root Domain Resolution"
- `docs/07-GLOSSARY.md`
- `docs/08-COMMUNITY_MIGRATION_STRATEGY.md`
