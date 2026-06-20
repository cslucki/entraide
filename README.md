# BouclePro - Pair-Aidance And Human/AI Cooperation Platform

BouclePro is a French Organization-scoped platform for pair-aidance, mutual help, micro-skill transmission and human/AI cooperation.

The launch doctrine is simple:

- a person starts with a fuzzy intention, need, offer, curiosity or recommendation;
- BouclePro helps turn it into a useful Interaction;
- AI may clarify, structure, suggest and summarize;
- a human always validates before publication or durable action;
- the Interaction is addressed to the right Organization, Loop or circle.

BouclePro is not a chatbot, not Slack, not WhatsApp, not a classical marketplace and not a job board.

---

# Launch Vision

BouclePro is designed to help people and organizations create calmer, more human and more useful cooperation spaces.

The platform focuses on:

- pair-aidance and mutual help
- transmission of micro-skills
- useful introductions between people
- Organization-scoped collaboration
- collective memory
- human-validated AI assistance

Core philosophy:

- calm
- conversational
- modular
- lightweight
- trustworthy
- human-centered

Older capabilities such as services, transactions, points, messaging and workflows may exist or evolve as framed platform capabilities. They are not the core launch promise.

For current product doctrine, read:

- `docs/README.md`
- `docs/product/BOUCLE_ARCHITECTURE.md`
- `docs/product/INTERACTION_MODEL.md`
- `docs/product/LAUNCH_READINESS_2026-06-22.md`

---

# Current Product Doctrine

Official architectural rule:

```text
Organization = Tenant
Loop ≠ Tenant
Interaction ≠ Loop
```

Current target architecture:

```text
Platform
└── Organization
    └── Loops
        └── Members
            └── Interactions
```

Organizations are the primary security and business boundary.

Loops are social containers inside Organizations. A Loop can host conversations, documents, Journal entries, Flux, components and Interactions, but it is not a tenant.

Interactions are structured collaborative activities: requests, offers, statuses, recommendations, questions, decisions or contributions. They may appear inside a Loop, but they are not Loops.

---

# Technology Stack

## Stack Technique

| Couche                   | Technologie                                      |
|--------------------------|--------------------------------------------------|
| **Backend**              | Laravel 13 · PHP 8.4                             |
| **Base de données**      | SQLite *(dev)* · PostgreSQL *(production)*       |
| **Frontend**             | Blade · Alpine.js · Tailwind CSS                 |
| **Interface réactive**   | Livewire 4                                       |
| **Authentification**     | Laravel Breeze                                   |
| **Outils IA**            | Laravel Boost MCP                                |
| **Tests & QA**           | PHPUnit · Playwright                             |
| **Déploiement**          | Laravel Cloud                                    |
| **Environnement dev**    | WSL2 · Windows 11                                |

---

# Current Capabilities

## Organizations

Organizations are the primary business and security boundary of the platform.

An Organization owns:

- members
- loops
- services
- workflows
- messaging
- AI systems
- permissions

Organizations are isolated through multi-tenant architecture.

---

## Loops

Loops are collaborative social containers inside Organizations.

Examples:

- Innovation IA
- Graphistes Marseille
- Support interne
- LaunchPals

A Loop may contain:

- conversations
- services
- workflows
- resources
- collaborative interactions

Loops are NOT tenant boundaries.

---

## Interactions

Interactions are the core launch product concept.

LaunchPals interaction patterns include:

- `I can help with...`
- `I am looking for help with...`
- `I am currently fascinated by...`
- `I think these two people should meet...`

These are not Loop types, not tenant boundaries and not a database model requirement. They are structured ways to turn human intent into validated cooperation.

---

## Members

Members may:

- join loops
- publish or validate Interactions
- ask for help or offer help
- recommend useful connections
- communicate through messaging
- interact with AI systems

---

## Services

Services are a framed capability, not the heart of the launch doctrine.

Where enabled, members can publish services with:

- title
- description
- categories
- tags
- delivery mode
- points cost

Services may be contextualized inside loops.

---

## Transactions

Transactions are a framed capability for controlled exchanges, not a generic marketplace promise.

Transactions follow a controlled state machine:

```text
pending → accepted → buyer_done → completed
        ↘ refused
pending/accepted → cancelled
```

Critical guarantees:

- atomic point transfers
- append-only ledger
- tenant isolation
- policy validation

---

## Messaging

Messaging supports contextual cooperation. BouclePro must not be presented as Slack or WhatsApp.

Messaging includes:

- contextual discussions
- transaction-linked conversations
- unread tracking
- system messages
- Livewire real-time updates

---

## AI Architecture

AI is an assistance layer across the platform.

AI systems are designed to remain:

- provider-agnostic
- modular
- Organization-scoped
- prompt-driven
- human-validated

AI may:

- clarify
- structure
- suggest
- summarize

AI must not publish, decide, match people, create Loops or bypass permissions alone.

---

# Multi-Tenant Architecture

BouclePro is transitioning from a legacy Community-based architecture toward an Organization-native architecture.

Current compatibility layer may still expose:

```text
Community
community_id
ResolveCommunity
current_community
```

This remains temporarily acceptable during migration phases.

New developments should prefer:

```text
Organization
organization_id
ResolveOrganization
```

Migration strategy is:

- incremental
- compatibility-first
- Playwright-safe
- SQLite-compatible

---

# Installation (Development)

## Requirements

- PHP 8.4+
- Composer
- Node.js + npm
- SQLite
- Git
- WSL2 recommended on Windows

---

## Setup

```bash
composer install

npm install
npm run build

cp .env.example .env

php artisan key:generate

touch database/database.sqlite

php artisan migrate --seed

php artisan storage:link

php artisan serve
```

Open:

```text
http://localhost:8000
```

---

# Test Accounts

After:

```bash
php artisan migrate --seed
```

| Email | Password | Role |
|---|---|---|
| test@example.com | password | Member |
| alice@example.com | password | Member |
| admin@example.com | password | Super Admin |

Each account starts with:

```text
100 points
```

---

# Development Workflow

The project follows a multi-agent AI workflow.

Main files:

```text
CLAUDE.md
AGENTS.md
```

Important documentation:

```text
docs/
ai/context/
ai/workflows/
```

---

# Playwright QA

Playwright validation is mandatory for critical flows.

Examples:

```bash
npx playwright test

npx playwright test --ui

npx playwright show-report
```

Critical QA domains:

- authentication
- tenant isolation
- organization isolation
- messaging
- responsive behavior
- Livewire stability
- console errors

---

# Architecture Principles

The platform prioritizes:

- tenant isolation
- business integrity
- maintainability
- conceptual clarity
- explicit logic
- stable migrations
- modular architecture

Avoid:

- giant rewrites
- architecture drift
- terminology drift
- premature over-engineering

---

# Product Philosophy

BouclePro should feel:

- calm
- obvious
- modern
- lightweight
- conversational
- trustworthy

The platform should reduce complexity,
not increase it.

AI should simplify action,
not create artificial conversational noise.

---

# Deployment

Production deployment uses Laravel Cloud.

Deployment pipeline:

- auto-deploy on `main`
- automatic migrations
- isolated environments
- PostgreSQL production database

---

# Roadmap Direction

BouclePro evolves toward:

- AI-assisted organizations
- modular collaboration
- white-label deployments
- federated collaboration
- organizational automation
- AI-native workflows
- plugin architecture
- enterprise-ready deployments

---

# Links

- Production: https://bouclepro.com
- Association AMT: https://amteletravail.fr
- GitHub: https://github.com/cslucki/entraide

---

# Strategic Principle

Priority order:

```text
conceptual clarity
→ documentation alignment
→ AI alignment
→ architecture stabilization
→ code migration
```

The goal is:

```text
A stable, understandable, organization-native V1
```
