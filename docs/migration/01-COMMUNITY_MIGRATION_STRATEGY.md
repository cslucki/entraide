# 01-COMMUNITY_MIGRATION_STRATEGY.md

**Update Date : 11/05/2026 - 20h45**

Strategic migration document for the transition:

```text
Community → Organization
```

This document defines:

* conceptual migration,
* vocabulary migration,
* architectural migration,
* Laravel migration strategy,
* AI alignment strategy,
* progressive compatibility rules.

References:

* `docs/05-DOMAIN_ARCHITECTURE.md`
* `docs/06-GLOSSARY.md`
* `docs/legacy/01-COMMUNITY_TRANSACTION_MATRIX.md` as legacy evidence only
* `docs/02-WORKFLOW_AND_ENGINEERING_PRINCIPLES.md`
* `docs/01-UI_RULES.md`
* `docs/04-ENGINEERING_RULES.md`

---

# 1. Migration Philosophy

This migration is NOT:

* a simple rename,
* a cosmetic refactor,
* a database operation.

This migration is:

* a domain architecture transition,
* a tenant model clarification,
* a product positioning evolution,
* an AI alignment stabilization process.

---

# 2. Strategic Goal

BouclePro evolves from:

```text
community platform
```

toward:

```text
organizational collaboration platform
```

The goal is to support:

* companies,
* associations,
* federations,
* professional networks,
* communities,
* collaborative ecosystems,
* AI-assisted organizations.

---

# 3. Core Architectural Decision

## Official Rule

```text
Organization = Tenant
```

NOT:

```text
Loop = Tenant
```

This is the foundational architectural rule.

---

# 4. Why the Migration Exists

The current system was initially designed around:

```text
Community
community_id
```

This terminology now creates several problems:

* conceptual ambiguity,
* AI prompt drift,
* future scaling limitations,
* federation confusion,
* multi-loop incompatibility,
* enterprise adoption limitations.

---

# 5. Current Situation

## Current Laravel Reality

The existing codebase still heavily relies on:

```text
Community
community_id
Community middleware
BelongsToTenantScope
```

This is acceptable temporarily.

The current system remains operational.

The migration must remain:

* progressive,
* safe,
* non-destructive,
* AI-compatible,
* backward-compatible.

---

# 6. Migration Priorities

## Priority Order

### Phase 1 — Conceptual Stabilization

Objectives:

* stabilize vocabulary,
* stabilize domain concepts,
* align AI prompts,
* align product language.

Deliverables:

* Domain Architecture V2
* Glossary
* Product vocabulary rules

---

### Phase 2 — Documentation Alignment

Objectives:

* align technical documentation,
* align UX documentation,
* align AI instructions,
* align onboarding language.

---

### Phase 3 — Product/UI Alignment

Objectives:

* expose "Organization" in UI,
* progressively remove "Community" from product language,
* stabilize user-facing terminology.

Important:
technical internals may still use:

```text
community_id
```

---

### Phase 4 — Laravel Compatibility Layer

Objectives:

* prepare safe abstraction layers,
* reduce direct dependency on legacy naming,
* avoid massive refactors.

---

### Phase 5 — Database Migration

Objectives:

* progressively introduce:

  * organization_id,
  * organizations table,
  * organization middleware,
  * organization policies.

This phase comes LATER.

---

# 7. Migration Constraints

The migration MUST:

* avoid production instability,
* avoid massive breaking changes,
* avoid giant refactors,
* avoid mixed migration branches.

In accordance with engineering workflow rules. 

---

# 8. Current Domain Mapping

| Legacy Concept       | Target Concept          |
| -------------------- | ----------------------- |
| Community            | Organization            |
| community_id         | organization_id         |
| Community Admin      | Organization Admin      |
| CommunityRequest     | OrganizationRequest     |
| community middleware | organization middleware |

---

# 9. Loop Introduction Strategy

## Important Clarification

The previous system used:

```text
Community
```

for BOTH:

* tenant boundary,
* collaborative group.

This is now separated into:

| New Concept  | Responsibility                     |
| ------------ | ---------------------------------- |
| Organization | Tenant / security / billing        |
| Loop         | Collaboration / relational context |

This separation is critical.

---

# 10. Compatibility Strategy

## Temporary Compatibility Rule

The system may temporarily expose:

```php
community_id
```

internally,

while exposing:

```text
Organization
```

externally.

This dual vocabulary period is intentional.

---

# 11. UI Migration Strategy

## Immediate Rule

All NEW UI should prefer:

* Organization
* Loop
* Member

Even if backend models still use:

* Community
* community_id

---

## Forbidden New UI Terminology

Do NOT introduce new user-facing usages of:

* Community
* community_id
* tenant
* workspace

---

# 12. AI Migration Strategy

AI systems must immediately align with:

* Organization
* Loop
* Member

even before Laravel migration.

Reason:
AI prompt drift creates long-term instability.

---

# 13. Prompt Stabilization Rules

All future prompts should:

* use canonical terminology,
* avoid synonyms,
* preserve concept boundaries,
* avoid mixing Loop and Organization.

---

# 14. Laravel Migration Strategy

## Recommended Approach

Prefer:

* adapters,
* aliases,
* abstraction layers,
* progressive refactors.

Avoid:

* giant search/replace,
* massive branch rewrites,
* big-bang migrations.

---

## Recommended Intermediate Pattern

Example:

```php
class Organization extends Community
{
}
```

Temporary compatibility layers are acceptable.

---

# 15. Database Migration Strategy

## Current Situation

Current schema:

```text
communities
community_id
```

---

## Future Target

Future schema:

```text
organizations
organization_id
```

---

## Important Rule

Database migration is NOT urgent.

Conceptual clarity is more important first.

---

# 16. Middleware Migration Strategy

## Current

```text
community middleware
```

---

## Future

```text
organization middleware
```

---

## Recommended Approach

Temporary aliasing is preferred.

Example:

```php
'organization' => ResolveCommunity::class
```

during transition phases.

---

# 17. Route Migration Strategy

## Current

```text
/communities/{community}
```

or:

```text
/{community}/...
```

---

## Future Possibilities

```text
/org/{organization}
```

or:

```text
/{organization}/...
```

Final routing strategy remains open.

Not part of the current migration phase.

---

# 17.5 Root Domain & Default Organization Resolution (T075.1)

Le root domain n'est pas tenantless. La stratégie T075.1 établit :

### Résolution par contexte URL

1. **Platform global** — aucune Organization. Ex: `/`, `/login`, `/partenaires`, `/admin/*`.
2. **Default Organization** (`/{feature}`) — Organization par défaut de la plateforme. Ex: `/blog`, `/explorer`.
3. **Partner slug** (`/{partnerSlug}/{feature}`) — Organization liée au partnerSlug. Ex: `/bni/blog`.
4. **Authenticated personal** (`/dashboard`) — Organization du user connecté.
5. **Fail-safe** — blocage si route métier sans Organization résolue.

### Partner slug routing comme nouveau pattern de migration

Le pattern `/{partnerSlug}/{feature}` est un nouveau canal de résolution pour la migration :
- Il permet d'exposer les données d'une Organization partenaire sans authentification.
- Il remplace conceptuellement l'ancien `/{community}/...` pour les cas publics.
- Community / community_id / current_community reste legacy technique temporaire.
- Ne pas lancer de migration DB maintenant.

### Community legacy

- Community reste legacy technique temporaire.
- Ne pas introduire Community comme nouveau concept produit.
- Ne pas migrer la DB maintenant.

---

# 18. Multi-Tenant Clarification

Current system:

* single organization per user,
* strong tenant isolation,
* scoped transactions.

Documented in the transaction matrix. 

Future evolution may support:

* multiple organizations per member,
* federation,
* shared loops,
* inter-organization collaboration.

---

# 19. Risks During Migration

## Main Risks

| Risk                          | Impact |
| ----------------------------- | ------ |
| Vocabulary drift              | High   |
| Loop / Organization confusion | High   |
| Mixed terminology in prompts  | High   |
| Massive refactors             | High   |
| AI hallucinated architecture  | High   |
| Multi-agent inconsistency     | High   |

---

# 20. Anti-Patterns

Avoid:

* renaming everything immediately,
* giant migration PRs,
* mixing architecture and UI migrations,
* premature database rewrites,
* changing tenant logic too early.

---

# 21. Recommended Migration Rhythm

Preferred progression:

```text
Concepts
→ Documentation
→ Prompts
→ UI
→ Services
→ Middleware
→ Models
→ Database
```

NOT the reverse.

---

# 22. Multi-Agent Coordination

Since multiple AI systems and agents contribute to the project:

* terminology must remain stable,
* migration phases must remain explicit,
* architectural rules must be documented centrally.

In accordance with engineering workflow rules. 

---

# 23. Product Positioning Impact

The migration also changes positioning.

Before:

```text
community exchange platform
```

After:

```text
organizational collaboration platform
```

This enables:

* enterprise adoption,
* white-label deployments,
* modular SaaS,
* AI-native organizations,
* federation models,
* advanced workflows.

---

# 24. Strategic Outcome

Target architecture:

```text
Platform
└── Organization
    └── Loops
        └── Members
            └── Interactions
```

The migration is successful when:

* vocabulary is stable,
* AI systems are aligned,
* UI is coherent,
* architecture is understood,
* Laravel internals become progressively compatible,
* database migration becomes low-risk.

---

# 25. Final Principle

The migration is primarily:

* conceptual,
* architectural,
* strategic.

It is NOT primarily technical.

The technical migration should become:

* predictable,
* progressive,
* almost mechanical,

because the conceptual layer was stabilized first.
