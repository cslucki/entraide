# Architecture Overview

## Project Type

BouclePro is a multi-tenant organizational collaboration platform built with Laravel.

The platform enables members to:

- publish services
- exchange points
- communicate through messaging
- collaborate inside loops
- participate in organizational workflows
- interact with AI-assisted systems

The application is entirely in French.

---

# Core Architectural Principles

## Organization-Native Architecture

BouclePro is transitioning from a legacy community-based architecture toward an organization-native architecture.

Official architectural rule:

```text
Organization = Tenant
````

NOT:

```text
Loop = Tenant
```

This distinction is fundamental.

Organizations represent:

* security boundaries
* billing boundaries
* governance boundaries
* tenant isolation boundaries

Loops represent:

* collaborative spaces
* relational contexts
* operational subgroups
* internal organizational dynamics

Loops are NOT:

* tenant boundaries
* database isolation layers
* security scopes

---

## Legacy Compatibility Layer

The current Laravel implementation still relies partially on legacy terminology:

```text
Community
community_id
ResolveCommunity
BelongsToTenantScope
```

This remains temporarily acceptable during the migration phase.

The migration toward:

```text
Organization
organization_id
ResolveOrganization
```

is progressive and incremental.

Business terminology and technical terminology may temporarily differ intentionally.

Rules:

* UI must prefer Organization terminology
* prompts must prefer Organization terminology
* documentation must prefer Organization terminology
* legacy Laravel internals may temporarily keep Community terminology
* tenant isolation must remain stable during all migration phases

---

## Multi-Tenant First

Tenant isolation is a critical architectural rule.

Each Organization:

* has isolated data
* owns its internal loops
* isolates transactions
* isolates messaging contexts
* isolates business workflows

Tenant isolation must NEVER be bypassed.

Main current tenant mechanisms may still include:

```text
community_id
ResolveCommunity
BelongsToTenantScope
```

during compatibility phases.

Future mechanisms may progressively evolve toward:

```text
organization_id
ResolveOrganization
OrganizationScope
```

---

## UUID Architecture

All primary keys use UUIDs.

Rules:

* never use incremental IDs
* always use `uuid('id')->primary()`
* preserve UUID consistency across relations

---

## Thin Controllers

Controllers should:

* validate requests
* authorize actions
* delegate business logic

Avoid:

* large business logic inside controllers
* duplicated logic
* tenant logic duplication

---

## Business Integrity

Critical business flows:

* points system
* transactions
* tenant isolation
* messaging
* reviews
* permissions

These systems must remain stable and coherent.

---

# Main Domains

## Organization

An Organization is:

* the tenant boundary
* the administration boundary
* the governance boundary
* the business boundary

Organizations may contain:

* members
* loops
* services
* workflows
* AI systems
* modules

---

## Loop

A Loop is a collaborative context inside an Organization.

A Loop may contain:

* conversations
* services
* requests
* workflows
* AI assistants
* collaborative interactions

A Loop is NOT:

* a tenant
* a security boundary
* a database isolation layer

---

## Member

A Member belongs to an Organization.

A Member may:

* join multiple loops
* create services
* participate in transactions
* communicate
* publish content
* interact with AI systems

---

## Services

Members publish services:

* title
* description
* category
* skills
* tags
* delivery mode
* points cost

Services belong to Organizations.

Services may optionally be contextualized inside Loops.

---

## Transactions

Transactions follow a strict state machine:

```text
pending → accepted → buyer_done → completed
        ↘ refused
pending/accepted → cancelled
```

Rules:

* financial consistency is critical
* operations must be atomic
* point ledger must remain append-only
* tenant isolation must remain enforced

---

## Messaging

Messaging uses:

* Livewire
* polling
* unread tracking
* system messages

Performance must remain controlled.

Messaging must remain organization-scoped.

---

## Reviews

Reviews:

* are linked to completed transactions
* affect member reputation
* must remain consistent with transaction state

---

## AI Architecture

AI is a transversal layer across the platform.

AI systems must remain:

* configurable
* provider-agnostic
* prompt-driven
* organization-scoped

AI may be contextualized:

* globally
* per organization
* per loop
* per workflow

Future AI capabilities:

* assistants
* automation
* recommendations
* moderation
* onboarding
* analytics
* semantic search
* memory systems

---

## Admin System

Admin areas manage:

* organizations
* members
* services
* transactions
* moderation
* settings
* workflows

Admin actions must never bypass:

* policies
* tenant integrity
* business consistency

---

# Frontend Architecture

Frontend stack:

* Blade
* Alpine.js
* Tailwind CSS
* Livewire

Rules:

* keep Livewire components lightweight
* avoid unnecessary polling
* validate UI behavior with browser tools
* preserve responsive stability
* avoid terminology drift

Preferred UI terminology:

* Organization
* Loop
* Member

Avoid exposing:

* Community
* tenant
* workspace

in public UI.

---

# API Architecture

API uses:

* Laravel Sanctum
* token authentication
* REST endpoints

Rules:

* authorization is mandatory
* tenant isolation applies to APIs
* never expose cross-organization data

---

# Testing Philosophy

Critical domains require tests:

* policies
* transactions
* points system
* tenant isolation
* API authorization
* organization isolation

Prefer:

* integration tests
* business-flow tests
* policy tests

Critical QA validation:

* Playwright
* Livewire stability
* browser console validation
* responsive behavior
* tenant isolation verification

---

# Architectural Safety Rules

Before modifying:

* tenant logic
* transactions
* point system
* policies
* scopes
* organization resolution
* middleware

Always:

1. inspect architecture
2. inspect related models
3. inspect policies
4. inspect routes
5. inspect tests
6. validate side effects

---

# Preferred Development Philosophy

Prefer:

* progressive migration
* compatibility layers
* incremental refactors
* architecture clarity
* maintainable solutions

Avoid:

* giant search/replace
* uncontrolled rewrites
* terminology drift
* mixed architectural concepts
* premature over-engineering

---

# Strategic Direction

BouclePro evolves toward:

* AI-assisted organizations
* modular collaboration
* organizational workflows
* white-label deployments
* federated collaboration
* AI-native ecosystems
* organization-scoped automation

The priority is:

```text
conceptual clarity first
code migration second
```

The architecture must remain:

* understandable
* stable
* modular
* maintainable
* organization-native
