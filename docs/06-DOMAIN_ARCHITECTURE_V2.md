# 06-DOMAIN_ARCHITECTURE_V2
**Update Date : 11/05/2026 - 20h33**
## Status

Draft — Strategic Foundation Document

---

# 1. Vision

BouclePro is no longer only a “community platform”.

BouclePro becomes:

> A modular organizational collaboration platform.

The platform is designed to help organizations structure:
- collaboration,
- knowledge sharing,
- mutual aid,
- exchanges,
- networking,
- internal communities,
- AI-assisted workflows.

The architecture is:
- modular,
- AI-ready,
- multi-tenant,
- scalable,
- provider-agnostic.

---

# 2. Core Philosophy

BouclePro must feel:
- calm,
- human,
- conversational,
- lightweight,
- trustworthy.

The product should reduce complexity instead of increasing it.

AI must simplify action,
not create artificial conversational noise.

---

# 3. Platform Hierarchy

```text
Platform
└── Organization
    └── Loops
        └── Members
            └── Interactions
````

---

# 4. Core Concepts

## 4.1 Platform

The Platform is the global BouclePro system.

Responsibilities:

* authentication infrastructure,
* billing,
* module system,
* AI infrastructure,
* federation,
* deployment orchestration,
* shared services.

Examples:

* bouclepro.com
* enterprise deployments
* white-label infrastructure

---

## 4.2 Organization

The Organization is the primary business and security boundary.

An Organization:

* owns data,
* owns members,
* owns modules,
* owns AI configurations,
* defines permissions,
* defines governance.

The Organization is the true tenant of the platform.

Examples:

* Cyberworkers
* CPME
* Air France
* BNI Marseille

---

## 4.3 Loop

A Loop is a relational and collaborative group inside an organization.

A Loop is NOT:

* a tenant,
* a security boundary,
* a database isolation layer.

A Loop is:

* social,
* collaborative,
* contextual,
* operational.

Examples:

* “Graphistes Marseille”
* “Innovation IA”
* “Recrutement”
* “Cabin Crew”

A Loop may contain:

* conversations,
* services,
* requests,
* events,
* resources,
* AI assistants.

---

## 4.4 Member

A Member is a user belonging to an Organization.

A Member may:

* join multiple loops,
* create services,
* participate in transactions,
* communicate,
* publish content,
* interact with AI systems.

---

## 4.5 Modules

BouclePro follows a modular architecture.

Organizations may activate or deactivate modules.

Current modules:

* Exchange
* Directory
* Messaging
* Blog
* Administration

Future modules:

* AI Assistant
* CRM
* LMS
* Payments
* Events
* Referrals
* Automation

---

# 5. Multi-Tenant Architecture

## Official Tenant Boundary

```text
Organization = Tenant
```

NOT:

```text
Loop = Tenant
```

---

## Why

Loops are collaborative contexts.

Organizations are:

* security boundaries,
* billing boundaries,
* governance boundaries,
* infrastructure boundaries.

This distinction is fundamental.

---

# 6. Interaction System

Interactions represent collaborative activity inside the platform.

Examples:

* transactions,
* messaging,
* requests,
* comments,
* reviews,
* AI interactions,
* collaborative workflows.

The interaction layer must remain:

* modular,
* auditable,
* extensible.

---

# 7. AI Architecture

AI is a transversal layer across the platform.

AI systems must remain:

* configurable,
* provider-agnostic,
* prompt-driven,
* organization-scoped.

AI may be contextualized:

* globally,
* per organization,
* per loop,
* per workflow.

Future AI capabilities:

* assistants,
* automation,
* recommendations,
* moderation,
* onboarding,
* analytics,
* semantic search,
* memory systems.

---

# 8. Federation Model

Organizations may interact through federation mechanisms.

Possible federation modes:

* public,
* private,
* enterprise,
* sovereign.

Examples:

* organization partnerships,
* shared loops,
* inter-organization collaboration,
* federated AI workflows.

Federation must remain optional.

---

# 9. Deployment Modes

## Public SaaS

Example:

```text
bouclepro.com/org/cpme
```

---

## Dedicated SaaS

Example:

```text
cpme.bouclepro.com
```

---

## Enterprise

Example:

```text
intranet.airfrance.local
```

---

## Future Self-Hosted

Possible future evolution.

Not part of the current MVP scope.

---

# 9.5 Root Domain Resolution

## Rule

The root domain is **not tenantless**.

```text
Organization = Tenant
```

Therefore, the root domain (`test.laravel` in dev, `bouclepro.com` in production) resolves the **default Organization**.

## Rationale

- The root domain is the primary entry point for all users.
- Treating the root domain as "outside Organization" would create:
  - a security breach,
  - an architectural inconsistency,
  - a bypass of the tenant model.
- A Loop is never created outside an Organization.
- A Loop never becomes the tenant.

## Resolution Strategy

Two canonical approaches:

| Approach | Description |
| -------- | ----------- |
| Internal resolution | The root domain implicitly resolves a default Organization (e.g. public page with implicit tenant context) |
| Redirect | The root domain redirects to an Organization-scoped canonical route (e.g. `/org/{slug}`) |

## Guard State

If no Organization can be resolved from:
- the route,
- the host,
- the authenticated user,
- or an explicit default Organization,

the application must fail safely (404, 410, or documented setup redirect).

## Application

This rule applies to all routes accessed via the root domain without an explicit Organization scope:

```text
/loops
/loops/create
/dashboard
/services
/requests
/messages
/blog
/membres
```

`/admin/*` routes are Platform global by design and are not Organization-scoped.

All environments (dev, staging, production) must follow this rule.

---

# 9.6 Partner / Co-branding

Partner est une entrée de **co-branding / distribution**, pas un tenant.

| Concept | Rôle |
|---------|------|
| Partner | Entrée publique co-brandée. Point d'entrée vers une Organization. |
| Organization | Tenant. Frontière de sécurité, billing, gouvernance. |
| Loop | Groupe collaboratif interne à une Organization. |

Un Partner peut pointer vers une Organization (ex: BNI = Partner + Organization).
Les Loops appartiennent toujours à une Organization, jamais à un Partner directement.

---

# 9.7 URL Context Resolution

| Niveau | Routes | Comportement |
|--------|--------|-------------|
| Platform global | `/`, `/login`, `/register`, `/password/*`, `/mentions-legales`, `/sitemap.xml`, `/partners`, `/admin/*` | Aucune Organization requise |
| Default Organization | `/blog`, `/explorer`, `/membres`, `/loops`, `/services`, `/requests`, `/messages` | Organization par défaut de la plateforme |
| Partner slug | `/{partnerSlug}`, `/{partnerSlug}/blog`, etc. | Organization partenaire |
| Authenticated personal | `/dashboard` | Organization du user connecté |
| Fail-safe | Route métier sans Org résolue | Blocage / redirect / 404 |

**Public ≠ global.** `/{partnerSlug}` est publique mais Organization-scopée.

---

# 9.8 Organisation Scoping Rule

Toutes les fonctionnalités métier actuelles et futures sont Organization-scopées.

Aucune feature métier ne doit fonctionner sans Organization résolue.
Les futures features ne doivent jamais être ajoutées comme routes Platform globales par défaut.
Toute exception doit être explicitement documentée et validée.

---

# 10. Legacy Compatibility

The current Laravel implementation still relies heavily on:

```text
community
community_id
```

This terminology remains temporarily for technical compatibility.

The migration toward:

```text
organization
organization_id
```

will be progressive.

Business terminology and technical terminology may temporarily differ.

This is intentional.

---

# 11. Naming Strategy

## Business Language

Preferred terminology:

* Organization
* Loop
* Member
* Organization Admin

---

## Legacy Technical Language

Temporary technical terminology:

* Community
* community_id
* Community middleware
* Community scope

---

## Rule

Business terminology has priority in:

* UI,
* onboarding,
* documentation,
* prompts,
* product communication.

Technical terminology may remain temporarily in:

* Laravel models,
* migrations,
* legacy routes,
* database schema.

---

# 12. Future Architecture Directions

The platform is designed to evolve toward:

* modular workspaces,
* AI-native workflows,
* organization automation,
* plugin systems,
* agent ecosystems,
* public APIs,
* white-label deployments,
* federated collaboration.

---

# 13. Architectural Principles

The platform must remain:

* coherent,
* intentional,
* calm,
* scalable,
* modular,
* human-centered.

The platform must NOT feel:

* improvised,
* overloaded,
* AI-generated,
* enterprise-heavy.

---

# 14. Strategic Principle

The next architectural priority is not code migration.

The priority is:

* conceptual stabilization,
* vocabulary stabilization,
* domain clarity,
* AI alignment,
* documentation alignment.

Code migration comes later.

```
