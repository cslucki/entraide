# 07-GLOSSARY.md
**Update Date : 11/05/2026 - 20h41**

Vocabulary stabilization document for BouclePro / Cyberworkers.

Goals:

* stabilize concepts,
* prevent AI prompt drift,
* unify documentation,
* prepare the Community → Organization migration,
* align Laravel / AI / UX / Product terminology.

References:

* Domain Architecture V2 
* Engineering Workflow Rules 
* UI Rules 
* Community Transaction Matrix 

---

# 1. Fundamental Rule

The main product language is:

```text
French
```

But critical system concepts remain:

```text
English
```

in order to:

* stabilize AI prompts,
* avoid ambiguity,
* preserve technical consistency,
* prepare future APIs,
* simplify AI integrations,
* maintain a shared language across:

  * product,
  * code,
  * documentation,
  * AI systems,
  * architecture.

---

# 2. Official System Concepts

These terms are considered:

* official,
* stable,
* canonical.

They must NOT drift.

| Official Concept | Type                | Description                  |
| ---------------- | ------------------- | ---------------------------- |
| Platform         | Core System         | Global BouclePro system      |
| Organization     | Tenant Boundary     | Primary organization         |
| Loop             | Collaborative Group | Internal collaborative group |
| Member           | User Role           | Organization user            |
| Module           | Architecture        | Activatable functionality    |
| Tenant           | Infrastructure      | Isolation boundary           |
| Interaction      | Domain Layer        | Collaborative activity       |
| Workflow         | Process             | Business flow                |
| Scope            | Security Concept    | Access limitation            |
| Provider         | AI Layer            | AI provider                  |
| Prompt           | AI Layer            | AI instruction               |
| Agent            | AI System           | Autonomous AI system         |

---

# 3. Vocabulary Mapping

## 3.1 Organization

### Official Term

```text
Organization
```

### Authorized French Translation

```text
organisation
```

### Temporarily Accepted Legacy Terms

```text
Community
community
community_id
```

### Forbidden Synonyms

```text
group
network
space
tenant
workspace
```

### Definition

The Organization represents:

* the business boundary,
* the security boundary,
* the billing boundary,
* the administration boundary.

The Organization is the true tenant of the platform.

---

## 3.2 Loop

### Official Term

```text
Loop
```

### Authorized French Translation

```text
boucle
```

### Forbidden Synonyms

```text
community
tenant
organization
workspace
```

### Definition

A Loop is:

* a collaborative space,
* relational,
* contextual,
* internal to an Organization.

A Loop is NOT:

* a tenant,
* a security boundary,
* a database isolation layer.

---

## 3.3 Member

### Official Term

```text
Member
```

### Authorized French Translation

```text
membre
```

### Forbidden Synonyms

```text
client
subscriber
contact
```

### Definition

A Member belongs to an Organization
and may participate in multiple Loops.

---

## 3.4 Platform

### Official Term

```text
Platform
```

### Authorized French Translation

```text
plateforme
```

### Definition

The Platform represents:

* the global infrastructure,
* the BouclePro system,
* shared services,
* AI architecture,
* billing,
* modules.

---

## 3.5 Module

### Official Term

```text
Module
```

### Authorized French Translation

```text
module
```

### Definition

A Module is an activatable feature
enabled per Organization.

---

## 3.6 Tenant

### Official Term

```text
Tenant
```

### Authorized French Translation

```text
tenant
```

### Definition

The Tenant represents:

* logical isolation,
* security isolation,
* business isolation.

In BouclePro:

```text
Organization = Tenant
```

and NOT:

```text
Loop = Tenant
```

---

## 3.7 Interaction

### Official Term

```text
Interaction
```

### Authorized French Translation

```text
interaction
```

### Definition

Interactions represent:

* transactions,
* messages,
* comments,
* reviews,
* AI exchanges,
* collaborative workflows.

---

# 4. Legacy Mapping

## Conceptual Migration

| Legacy               | New Target              |
| -------------------- | ----------------------- |
| Community            | Organization            |
| community_id         | organization_id         |
| Community Admin      | Organization Admin      |
| CommunityRequest     | OrganizationRequest     |
| community middleware | organization middleware |

---

# 5. Naming Rules

## UI / Product

Always prefer:

```text
Organization
Loop
Member
```

even in a French interface.

Examples:

```text
Create an Organization
Join a Loop
Invite a Member
```

---

## Technical Documentation

Temporarily allowed:

```text
Community
community_id
```

ONLY:

* when describing the current Laravel implementation,
* for legacy compatibility,
* for future migration planning.

---

## Database

Temporarily accepted:

```text
community_id
```

until official migration.

---

## AI Prompts

Prompts must use:

* official terminology,
* identical concepts,
* identical conventions.

Avoid:

* multiple synonyms,
* terminology variations,
* ambiguous wording.

---

# 6. Forbidden Synonyms

## For Organization

Forbidden:

* space
* network
* group
* tenant
* workspace

---

## For Loop

Forbidden:

* community
* organization
* team
* tenant

---

## For Member

Forbidden:

* client
* prospect
* end user

---

# 7. AI Alignment Rules

AI systems must consider:

```text
Organization
Loop
Member
```

as:

* canonical vocabulary,
* stable terminology,
* priority concepts.

---

# 8. Documentation Rules

All new documents must:

* follow the glossary,
* avoid terminology drift,
* reference official concepts,
* preserve stable system vocabulary.

---

# 9. Product Philosophy Vocabulary

Encouraged vocabulary:

```text
calm
human
conversational
modular
lightweight
trustworthy
intentional
scalable
AI-ready
```

Discouraged vocabulary:

```text
disruptive
revolutionary
AI-powered everywhere
futuristic
ultra-automation
growth hacking
```

In accordance with product and UX rules.   

---

# 10. Strategic Rule

Before any major technical migration:

priority goes to:

1. conceptual stabilization,
2. vocabulary stabilization,
3. AI alignment,
4. documentation alignment,
5. target architecture,
6. technical migration.

Never the reverse.
