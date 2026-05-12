````md
# BouclePro — Organizational Collaboration Platform

BouclePro is a French multi-tenant organizational collaboration platform built with Laravel.

The platform enables organizations to:

- structure collaborative ecosystems
- publish and exchange services
- organize internal loops
- exchange value through a points system
- communicate through contextual messaging
- manage collaborative workflows
- integrate AI-assisted systems

BouclePro evolves toward an organization-native, AI-ready and modular architecture.

---

# Vision

BouclePro is designed to help organizations create calmer, more human and more collaborative digital environments.

The platform focuses on:

- collaboration
- mutual aid
- organizational workflows
- knowledge sharing
- professional networking
- AI-assisted productivity

Core philosophy:

- calm
- conversational
- modular
- lightweight
- trustworthy
- human-centered

---

# Current Architecture

Official architectural rule:

```text
Organization = Tenant
Loop ≠ Tenant
```

Current target architecture:

```text
Platform
└── Organization
    └── Loops
        └── Members
            └── Interactions
```

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

# Core Features

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

Loops are collaborative contexts inside Organizations.

Examples:

- Innovation IA
- Graphistes Marseille
- Recrutement
- Support interne

A Loop may contain:

- conversations
- services
- workflows
- resources
- collaborative interactions

Loops are NOT tenant boundaries.

---

## Members

Members may:

- join loops
- publish services
- exchange points
- participate in transactions
- communicate through messaging
- interact with AI systems

---

## Services

Members can publish services with:

- title
- description
- categories
- tags
- delivery mode
- points cost

Services may be contextualized inside loops.

---

## Transactions

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

Messaging includes:

- contextual discussions
- transaction-linked conversations
- unread tracking
- system messages
- Livewire real-time updates

---

## AI Architecture

AI is a transversal layer across the platform.

AI systems are designed to remain:

- provider-agnostic
- modular
- organization-scoped
- prompt-driven

Future AI capabilities include:

- assistants
- automation
- recommendations
- moderation
- semantic search
- organizational memory systems

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
````
