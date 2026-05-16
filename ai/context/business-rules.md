# Business Rules

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