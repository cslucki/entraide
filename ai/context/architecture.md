# Architecture Overview

## Project Type

Entraide is a multi-tenant peer-to-peer service exchange platform built with Laravel.

Users:
- publish services
- exchange points
- communicate through messaging
- perform transactions
- interact inside isolated communities

The application is entirely in French.

---

# Core Architectural Principles

## Multi-Tenant First

The platform is community-based.

Each tenant/community:
- has its own slug
- has isolated data
- must remain isolated at all times

Tenant isolation is a critical architectural rule.

Main tenant mechanism:
- `community_id`
- `ResolveCommunity` middleware
- `BelongsToTenantScope`

---

## UUID Architecture

All primary keys use UUIDs.

Rules:
- never use incremental IDs
- always use `uuid('id')->primary()`
- preserve UUID consistency across relations

---

## Thin Controllers

Controllers should:
- validate requests
- authorize actions
- delegate business logic

Avoid:
- large business logic inside controllers
- duplicated logic

---

## Business Integrity

Critical business flows:
- points system
- transactions
- tenant isolation
- messaging
- reviews

These systems must remain stable and coherent.

---

# Main Domains

## Services

Users publish services:
- title
- description
- category
- skills
- tags
- delivery mode
- points cost

Services belong to communities.

---

## Transactions

Transactions follow a strict state machine:

pending → accepted → buyer_done → completed
        ↘ refused
pending/accepted → cancelled

Rules:
- financial consistency is critical
- operations must be atomic
- point ledger must remain append-only

---

## Messaging

Messaging uses:
- Livewire
- polling
- unread tracking
- system messages

Performance must remain controlled.

---

## Reviews

Reviews:
- are linked to completed transactions
- affect user ratings
- must remain consistent with transaction state

---

## Admin System

Admin area manages:
- users
- services
- transactions
- reports
- communities
- settings

Admin actions must never bypass:
- policies
- tenant integrity
- business consistency

---

# Frontend Architecture

Frontend stack:
- Blade
- Alpine.js
- Tailwind CSS
- Livewire

Rules:
- keep Livewire components lightweight
- avoid unnecessary polling
- validate UI behavior with browser tools

---

# API Architecture

API uses:
- Laravel Sanctum
- token authentication
- REST endpoints

Rules:
- authorization is mandatory
- tenant isolation applies to APIs
- never expose cross-tenant data

---

# Testing Philosophy

Critical domains require tests:
- policies
- transactions
- points system
- tenant isolation
- API authorization

Prefer:
- integration tests
- business-flow tests
- policy tests

---

# Architectural Safety Rules

Before modifying:
- tenant logic
- transactions
- point system
- policies
- scopes

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
- maintainability
- explicitness
- predictable behavior
- small safe refactors

Avoid:
- unnecessary abstractions
- hidden magic
- uncontrolled refactors
- breaking tenant isolation