> **AGENT CONTEXT ONLY**
>
> This file is an operational summary for agents. It is not canonical project documentation. If this file conflicts with `docs/`, `docs/` wins.

# Business Rules

## Canonical Sources

- `docs/README.md`
- `docs/05-DOMAIN_ARCHITECTURE.md`
- `docs/06-GLOSSARY.md`
- `docs/migration/02-ORGANIZATION_MIGRATION_EXECUTION_PLAN.md`
- `docs/legacy/01-COMMUNITY_TRANSACTION_MATRIX.md` as legacy evidence only

Use this file as a business-safety reminder only.

## Organisation Scoping Rule

**Toutes les fonctionnalités métier actuelles et futures de BouclePro doivent être Organization-scopées.**

Aucune feature métier ne doit fonctionner sans Organization résolue.

Features concernées :
- Blog
- Explorer / échanges
- Annuaire / membres
- Services
- Requests / demandes
- Transactions
- Messages
- Loops
- Referrals
- CRM (futur)
- LMS / formation (futur)
- Events (futur)
- Objectifs (futur)
- IA / assistants (futur)
- Automatisations (futur)
- Notifications métier (futur)
- Tout futur module activable

Pattern cible :
- `/{feature}` → Organization par défaut de la plateforme.
- `/{partnerSlug}/{feature}` → Organization partenaire.

---

## Critical Rules

- Point ledger is append-only
- Financial operations must be atomic
- UUID architecture must never be broken
- Transaction state machine must remain valid
- Policies are mandatory for protected actions

## Transaction State Machine

pending → accepted → buyer_done → completed
        ↘ refused
pending/accepted → cancelled

## Points System

- New user receives welcome bonus
- Point ledger is immutable
- Balance updates must be transactional

## Safety Rules

Never modify:
- tenant integrity
- transaction consistency
- point ledger integrity
without explicit verification.
